<?php

namespace Thibitisha\KmpdcSeeder\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

class SyncKmpdcData extends Command
{
    protected $signature = 'kmpdc:sync';
    protected $description = 'Scrape and stream KMPDC practitioner data into a CSV file';

    public function handle()
    {
        $url = config('kmpdc-seeder.source_url');
        $csvDir = config('kmpdc-seeder.csv_storage_path');

        if (!file_exists($csvDir)) {
            mkdir($csvDir, 0775, true);
        }

        $timestamp = now()->format('Y_m_d_His');
        $csvPath = "{$csvDir}/{$timestamp}_" . config('kmpdc-seeder.csv_filename');

        $this->info("🌐 Fetching data from: {$url}");
        $this->info("📁 Streaming to CSV: {$csvPath}");

        $response = Http::timeout(config('kmpdc-seeder.request_timeout'))
            ->withHeaders([
                'User-Agent' => config('kmpdc-seeder.user_agent'),
            ])
            ->get($url);

        if (!$response->successful()) {
            $this->error("❌ Failed to fetch page: HTTP " . $response->status());
            return Command::FAILURE;
        }

        $html = $response->body();

        $csv = @fopen($csvPath, 'w');
        if (!$csv) {
            $this->error("❌ Could not create CSV file at: {$csvPath}");
            return Command::FAILURE;
        }

        // Table headers (exactly as shown on the site)
        $headers = [
            'Fullname',
            'Reg_No',
            'Address',
            'Qualifications',
            'Discipline',
            'Speciality',
            'Sub_Speciality',
            'Status',
            'View_URL'
        ];
        fputcsv($csv, $headers);

        $crawler = new Crawler($html);

        $rows = $crawler->filter('table tr'); // adjust if table ID differs
        
        if ($rows->count() === 0) {
            $this->warn("⚠️ No table rows found. Check selector or ensure JS content is server-rendered.");
            fclose($csv);
            return Command::FAILURE;
        }

        $this->info("📊 Found {$rows->count()} rows — streaming to CSV...");

        $count = 0;
        $rows->each(function (Crawler $row, $i) use ($csv, &$count) {
            if ($i === 0) return; // skip header

            $cells = $row->filter('td');
            Log::debug("KMPDC row cells: " . $cells->count());

            if ($cells->count() === 0) return;

            try {
                $fullname       = trim($cells->eq(0)->text());
                $address        = trim($cells->eq(2)->text());
                $qualifications = trim($cells->eq(3)->text());
                $discipline     = trim($cells->eq(4)->text());
                $speciality     = trim($cells->eq(5)->text());
                $subSpeciality  = trim($cells->eq(6)->text());
                $status         = trim($cells->eq(7)->text());

                $viewLink = $cells->eq(8)->filter('a')->attr('href') ?? null;

                $regNo = null;

                if ($viewLink && preg_match('/client_id=(\d+)/', $viewLink, $matches)) {
                    $regNo = $matches[1];
                }

                $rowData = [
                    $fullname,
                    $regNo,
                    $address,
                    $qualifications,
                    $discipline,
                    $speciality,
                    $subSpeciality,
                    $status,
                    $viewLink ? 'https://kmpdc.go.ke/Registers/' . $viewLink : null,
                ];

                if (fputcsv($csv, $rowData) === false) {
                    Log::warning('Failed to write row for: ' . $fullname);
                }

                $count++;

                // Flush buffer every 50 rows
                if ($count % 50 === 0) {
                    fflush($csv);
                    $this->info("✅ Processed {$count} rows...");
                }

            } catch (\Throwable $e) {
                Log::warning("KMPDC row parse error: " . $e->getMessage());
            }
        });

        fclose($csv);

        $fileSize = file_exists($csvPath) ? round(filesize($csvPath) / 1024, 2) : 0;
        $this->info("🎉 Done! Total rows written: {$count}");
        $this->info("📄 CSV saved to: {$csvPath} ({$fileSize} KB)");

        return Command::SUCCESS;
    }
}
