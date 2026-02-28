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
            // Handle various possible SUMMARY.md formats
            $yamlContent = preg_replace('/^---\s*$/m', '', $content);
            $summary = Yaml::parse($yamlContent);
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
                    $this->importItem($path, $lang, 'General', $categoryData);
                    $count++;
                }
            }
        }

        $this->info("Import completed. Total items processed: {$count}");
        $this->info("Images are stored in: public/storage/knowledge/ppanel-tutorial");
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
