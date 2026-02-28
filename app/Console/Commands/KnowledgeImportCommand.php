<?php

namespace App\Console\Commands;

use App\Models\Knowledge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class KnowledgeImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'knowledge:import {--path= : Path to ppanel-tutorial project} {--rollback : Rollback imported knowledge}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import or rollback ppanel-tutorial into Xboard knowledge base';

    private $sourceTag = '<!-- source:ppanel-tutorial -->';
    private $storagePath = 'app/public/knowledge/ppanel-tutorial';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('rollback')) {
            $this->rollback();
            return;
        }

        $path = $this->option('path');
        if (!$path || !File::isDirectory($path)) {
            $this->error('Please provide a valid path to ppanel-tutorial project using --path');
            return;
        }

        $summaryFile = $path . DIRECTORY_SEPARATOR . 'SUMMARY.md';
        if (!File::exists($summaryFile)) {
            $this->error('SUMMARY.md not found in the provided path');
            return;
        }

        try {
            $content = File::get($summaryFile);
            
            // 尝试解析为 YAML
            try {
                $yamlContent = preg_replace('/^---\s*$/m', '', $content);
                $summary = Yaml::parse($yamlContent);
                if (!is_array($summary)) {
                    throw new \Exception("Not a valid YAML format");
                }
            } catch (\Exception $e) {
                // 如果 YAML 解析失败，尝试解析 Markdown 列表格式 (GitBook 风格)
                $summary = $this->parseMarkdownSummary($content);
            }
        } catch (\Exception $e) {
            $this->error('Failed to parse SUMMARY.md: ' . $e->getMessage());
            return;
        }

        $this->info('Starting import...');
        $count = 0;

        foreach ($summary as $lang => $categories) {
            if (!is_array($categories)) continue;

            foreach ($categories as $categoryData) {
                $categoryName = $categoryData['title'] ?? 'General';
                
                // Handle subItems
                if (isset($categoryData['subItems']) && is_array($categoryData['subItems'])) {
                    foreach ($categoryData['subItems'] as $item) {
                        $this->importItem($path, $lang, $categoryName, $item);
                        $count++;
                    }
                } else {
                    // Direct item
                    if (isset($categoryData['path'])) {
                         $this->importItem($path, $lang, $categoryName, $categoryData);
                         $count++;
                    }
                }
            }
        }

        $this->info("Import completed. Total items processed: {$count}");
        $this->info("Images are stored in: public/storage/knowledge/ppanel-tutorial");
    }

    private function parseMarkdownSummary($content)
    {
        $lines = explode("\n", $content);
        $summary = [];
        $currentLang = 'en'; // Default fallback
        $currentCategory = 'General';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Match Category (## Header) or any header level
            if (preg_match('/^#+\s+(.+)$/', $line, $matches)) {
                $currentCategory = trim($matches[1]);
                continue;
            }

            // Match Link (* [Title](path)) or - [Title](path)
            if (preg_match('/^[\*\-]\s+\[(.*?)\]\((.*?)\)/', $line, $matches)) {
                $title = $matches[1];
                $path = $matches[2];
                
                // Determine language from path parts
                // Example: en-US/windows/README.md -> lang = en-US
                $pathParts = explode('/', $path);
                $lang = $currentLang;
                
                if (count($pathParts) > 0) {
                     // Check if first part looks like a language code (e.g., en, en-US, zh-CN)
                     if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $pathParts[0])) {
                         $lang = $pathParts[0];
                     }
                }

                if (!isset($summary[$lang])) {
                    $summary[$lang] = [];
                }
                
                // Find or create category in the summary array
                $categoryIndex = -1;
                foreach ($summary[$lang] as $index => $cat) {
                    if ($cat['title'] === $currentCategory) {
                        $categoryIndex = $index;
                        break;
                    }
                }

                if ($categoryIndex === -1) {
                    $summary[$lang][] = [
                        'title' => $currentCategory,
                        'subItems' => []
                    ];
                    $categoryIndex = count($summary[$lang]) - 1;
                }

                $summary[$lang][$categoryIndex]['subItems'][] = [
                    'title' => $title,
                    'path' => $path
                ];
            }
        }
        
        return $summary;
    }

    private function importItem($basePath, $lang, $category, $item)
    {
        $title = $item['title'] ?? 'Untitled';
        $relativeMdPath = $item['path'] ?? null;

        if (!$relativeMdPath) return;

        $fullMdPath = $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeMdPath);

        if (!File::exists($fullMdPath)) {
            $this->warn("File not found: {$fullMdPath}");
            return;
        }

        $body = File::get($fullMdPath);
        $mdDir = dirname($fullMdPath);

        // Process images
        $body = preg_replace_callback('/!\[(.*?)\]\((.*?)\)/', function ($matches) use ($mdDir, $lang, $category) {
            $altText = $matches[1];
            $imgRelativePath = $matches[2];

            // Skip remote images
            if (preg_match('/^https?:\/\//', $imgRelativePath)) {
                return $matches[0];
            }

            $imgFullPath = $mdDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $imgRelativePath);

            if (File::exists($imgFullPath)) {
                $fileName = basename($imgFullPath);
                // Create a unique path for the image in storage
                $destSubDir = "knowledge/ppanel-tutorial/{$lang}/" . Str::slug($category);
                $destDir = \storage_path("app/public/{$destSubDir}");

                if (!File::isDirectory($destDir)) {
                    File::makeDirectory($destDir, 0755, true);
                }

                File::copy($imgFullPath, $destDir . DIRECTORY_SEPARATOR . $fileName);

                // Return new Markdown image path
                $newPath = "/storage/{$destSubDir}/{$fileName}";
                return "![{$altText}]({$newPath})";
            }

            return $matches[0];
        }, $body);

        // Add rollback tag
        $body .= "\n\n" . $this->sourceTag;

        // Update or Create in DB
        Knowledge::updateOrCreate(
            [
                'language' => $lang,
                'title' => $title,
                'category' => $category,
            ],
            [
                'body' => $body,
                'show' => true,
                'sort' => null,
            ]
        );

        $this->line("Imported: [{$lang}] {$category} -> {$title}");
    }

    private function rollback()
    {
        $this->info('Starting rollback...');

        // 1. Delete records from DB
        $count = Knowledge::query()->where('body', 'like', "%{$this->sourceTag}%")->delete();
        $this->info("Deleted {$count} records from database.");

        // 2. Delete images from storage
        $fullStoragePath = \storage_path($this->storagePath);
        if (File::isDirectory($fullStoragePath)) {
            File::deleteDirectory($fullStoragePath);
            $this->info("Deleted images from storage: public/storage/knowledge/ppanel-tutorial");
        }

        $this->info('Rollback completed.');
    }
}
