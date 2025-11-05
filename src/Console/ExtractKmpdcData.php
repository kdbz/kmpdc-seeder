<?php

namespace Thibitisha\KmpdcSeeder\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use League\Csv\Reader;
use League\Csv\Statement;

use function PHPUnit\Framework\isArray;

class ExtractKmpdcData extends Command
{
    public static array $MMed = [
        "M.D",
        "M.D.",
        "M.D.STOMATOLOGY",
        "M.ME",
        "M.MED",
        "M.MED - MED",
        "M.MED - OBST &AMP GYNAE",
        "M.MED - PAED",
        "M.MED ORTHO",
        "M.MED PSYCH",
        "M.MED SURG",
        "M.MED SURGERY",
        "M.MED(",
        "M.MED( OBS&AMPGYN",
        "M.MED()INT.MED)",
        "M.MED(ANAE",
        "M.MED(CLIN.ONCOL)(",
        "M.MED(ENT)",
        "M.MED(GEN.SURG",
        "M.MED(HAEMATOLOGY)",
        "M.MED(INT.MED",
        "M.MED(INT.MED)",
        "M.MED(OBS &AMP GYN",
        "M.MED(OBS&AMPGYN",
        "M.MED(OBS&AMPGYNAE",
        "M.MED(OBST/GYNAE",
        "M.MED(PAED",
        "M.MED(RAD.)(",
        "M.MED(SURG)",
        "M.MED)",
        "M.MED.OBS'GYNAE",
        "M.MED.OPHT",
        "M.MED.OPHTH",
        "M.MED.PAED",
        "M.MED.SURGERY",
        "M.MEDD",
        "M.MEDE",
        "MMED",
        "MMED (PAED",
        "MMED CHEST &AMP RESP",
        "MMED INT.MED",
        "MMED(GEN.SURG",
        "MMED(GEN.SURG.URO",
        "MMED(INT.MED",
        "MMED(INT.MED)(CARD.",
        "MMED(OBS &AMP GYN",
        "MMED(OBS&AMPGYN",
        "MMED(PAED",
        "MMED(RAD",
        "MMED(SURG",
        "MMEDPSYCH",
        "MMEDSC",
    ];

    protected $signature = 'kmpdc:extract 
        {--csv= : Path to the KMPDC practitioners CSV file} 
        {--summary : Print summary instead of JSON export}';

    protected $description = 'Extract and normalize practitioner data (degrees, institutions, specialities, addresses) from the scraped CSV file.';

    public function handle()
    {
        // Ensure CSV directory is accessible
        $csvDir = config('kmpdc-seeder.csv_storage_path', storage_path('app/kmpdc-csv'));

        if (!File::exists($csvDir)) {
            $this->error("❌ CSV storage path not found: {$csvDir}");
            return Command::FAILURE;
        }

        $files = glob("{$csvDir}/*.csv");
        if (empty($files)) {
            $this->error('❌ No CSV files found. Run `php artisan kmpdc:sync` first.');
            return Command::FAILURE;
        }

        // Pick the latest file
        $csvPath = end($files);
        $this->info("📄 Reading from: {$csvPath}");
        if (!file_exists($csvPath)) {

            $this->error("❌ CSV file not found: {$csvPath}");
            return Command::FAILURE;
        }

        $this->info("📂 Reading data from: {$csvPath}");

        try {
            // Read CSV
            $csv = Reader::createFromPath($csvPath, 'r');
            $csv->setHeaderOffset(0);

            $records = iterator_to_array($csv->getRecords());
        } catch (\Throwable $e) {
            $this->error("❌ Failed to open or read CSV: " . $e->getMessage());
            return Command::FAILURE;
        }

        $this->info("🔍 Extracting normalized entities...");

        $degrees = [];
        $institutions = [];
        $addresses = [];
        $practitioners = [];
        $specialities = [];
        $subSpecialities = [];
        $statuses = [];

        $rowCount = 0;

        foreach ($records as $row) {
            $rowCount++;
            $fullname = $row['Fullname'] ?? '';
            $regNo = $row['Reg_No'] ?? '';
            $address = $row['Address'] ?? '';
            $qualifications = $row['Qualifications'] ?? '';
            $discipline = $row['Discipline'] ?? '';
            $speciality = $row['Speciality'] ?? '';
            $subSpeciality = $row['Sub_Speciality'] ?? '';
            $status = $row['Status'] ?? '';

            // collect unique status, specialities and sub-specialities
            if ($speciality) $specialities[$speciality] = true;
            
            if ($subSpeciality){
                if (!array_key_exists($speciality, $subSpecialities)){
                    $subSpecialities[$speciality] = [];
                }
                $subSpecialities[$speciality][] = $subSpeciality;
            }
            
            
            if ($status) $statuses[$status] = true;

            // Parse qualifications (extract year + degree text)
            $parsedQualifications = $this->parseQualifications($qualifications);

            // Collect unique degrees and institutions
            foreach ($parsedQualifications as $qual) {
                $degree = $qual['degree'] ?? '';
                $institution = $qual['institution'] ?? '';

                if ($degree) $degrees[$degree] = true;
                if ($institution) $institutions[$institution] = true;
            }

            if ($address) $addresses[$address] = true;

            $practitioners[] = [
                'full_name' => $fullname,
                'registration_number' => $regNo,
                'address' => $address,
                'discipline' => $discipline,
                'speciality' => $speciality,
                'sub_speciality' => $subSpeciality,
                'status' => $status,
                'qualifications' => $parsedQualifications,
            ];

            if ($rowCount % 200 === 0) {
                $this->line("   → Processed {$rowCount} practitioners...");
            }
        }
        
        //sort degrees, institutions, addresses
        ksort($degrees);
        ksort($institutions);
        ksort($addresses);
        //store unique sub-specialities under their specialities
        foreach ($subSpecialities as $speciality => $subs) {
            $subSpecialities[$speciality] = array_values(array_unique($subs));
        }

       
        ksort($specialities);
        ksort($statuses);


        $this->info("✅ Extraction complete. Processed {$rowCount} practitioners.");
        if ($this->option('summary')) {
            $this->table(
                ['Entity', 'Unique Count'],
                [
                    ['Practitioners', count($practitioners)],
                    ['Degrees', count($degrees)],
                    ['Institutions', count($institutions)],
                    ['Addresses', count($addresses)],
                ]
            );
        } else {
            $outputDir = storage_path('app/kmpdc-data');
            if (!file_exists($outputDir)) mkdir($outputDir, 0775, true);

            file_put_contents("{$outputDir}/practitioners.json", json_encode($practitioners, JSON_PRETTY_PRINT));
            file_put_contents("{$outputDir}/degrees.json", json_encode($degrees), JSON_PRETTY_PRINT);
            file_put_contents("{$outputDir}/statuses.json", json_encode(array_keys($statuses), JSON_PRETTY_PRINT));
            file_put_contents("{$outputDir}/specialities.json", json_encode(array_keys($specialities), JSON_PRETTY_PRINT));
            file_put_contents("{$outputDir}/subspecialities.json", json_encode($subSpecialities), JSON_PRETTY_PRINT);
            file_put_contents("{$outputDir}/institutions.json", json_encode(array_keys($institutions), JSON_PRETTY_PRINT));
            file_put_contents("{$outputDir}/addresses.json", json_encode(array_keys($addresses), JSON_PRETTY_PRINT));

            $this->info("✅ Data extracted successfully to: {$outputDir}");
        }

        return Command::SUCCESS;
    }

    /**
     * Parses qualifications string into structured array.
     * Extracts year, institution, degree, and speciality.
     * @param string $qualification
     * @return array
     * @example
     * Input: "MBChB(Nairobi) 2005, M.Med(Gen.Surg)(Nairobi) 2010"
     * Output: [
     *   [
     *     'year' => '2005',
     *     'institution' => 'Nairobi',
     *     'degree' => 'MBChB',
     *     'speciality' => '',
     *   ],
     *   [
     *     'year' => '2010',
     *     'institution' => 'Nairobi',
     *     'degree' => 'M.Med',
     *     'speciality' => 'Gen.Surg',
     *   ],
     * ]
     */
    protected function parseQualifications(string $qualification): array
    {
        $pattern = '/\d+/';
        preg_match_all($pattern, $qualification, $matches, PREG_OFFSET_CAPTURE);
        $qualArray = [];
        $start = 0;
        //use explode based on comma
        $qualifications = explode(',', $qualification);
        foreach ($qualifications as $qualification) {
            //get year from qualification
            $year = '';
            preg_match($pattern, $qualification, $yearMatch);

            if (!empty($yearMatch)) {
                $year = $yearMatch[0];
            }
            //remove year from qualification
            $qualification = preg_replace($pattern, '', $qualification);

            $degree_institution = $this->splitInstitutionAndDegree($qualification);

            if (is_array($degree_institution) === true) {
                $qualArray[] = [
                    'year' => $year,
                    'institution' => strtoupper($degree_institution['institution']),
                    'degree' => $this->replaceQualificationWithStandardized(strtoupper($degree_institution['degree'])),
                    'speciality' => strtoupper($this->convertHtmlEntities($degree_institution['speciality'])),
                ];
            }
        }

        return $qualArray;
    }
    /**
     * Sanitizes degree string by removing unwanted characters.
     * @param string $degree
     * @return string
     * @example
     * Input: "MBChB(Nairobi),"
     * Output: "MBChB(Nairobi)"
     */
    public function sanitizeDegree(string $degree): string
    {
        return trim(str_replace([',', ';'], '', $degree));
    }

    public function replaceQualificationWithStandardized(string $degree): string
    {
        foreach ($this->MMed as $standardDegree) {
            if ($standardDegree === $degree) {
                return "M.MED";
            }
        }

        return $degree;
    }

    /**
     * Splits the institution and degree from a given degree string.
     * @param string $degree
     * @return array|null
     * @example
     * Input: "M.Med(Gen.Surg)(Nairobi)"
     * Output: [
     *   'speciality' => 'Gen.Surg',
     *   'institution' => 'Nairobi',
     *   'degree' => 'M.Med',
     * 
     */
    public function splitInstitutionAndDegree(string $degree)
    {
        //sanitize
        $degree = $this->sanitizeDegree($degree);
        echo $degree . "\n";

        //make sure degree is not empty
        if (!empty($degree)) {
            /**
             * Degree(Institution) or Speciality(Degree)(Institution)
             * for example MBChB(Nairobi) or M.Med(Gen.Surg)(Nairobi)
             */
            // Degree(Institution)
            $pattern = '/\(([^()]+)\)$/';
            preg_match($pattern, $degree, $matches);
            // degree(institution) - two matches
            if (count($matches) === 2) {

                $institution = $this->convertHtmlEntities($matches[1]);
                $degreeName = trim(preg_replace($pattern, '', $degree));
                $speciality = '';

                //if degree contains ( then it has a speciality like M.Med(Gen.Surg
                if (strpos($degreeName, '(') !== false) {
                    // split based on first (
                    $specialityPattern = '/\(([^()]+)\)$/';
                    preg_match($specialityPattern, $degreeName, $specMatches);
                    if (count($specMatches) === 2) {
                        $speciality = $this->convertHtmlEntities($specMatches[1]);
                        $degreeName = trim(preg_replace($specialityPattern, '', $degreeName));
                    }
                }



                return [
                    'speciality' => $speciality,
                    'institution' => $institution,
                    'degree' => $degreeName,
                ];
            }
        }
    }

    /**
     * Converts HTML entities in a string to normal text.
     * @param string $text
     * @return string
     * @example
     * Input: "Obs&ampGynae"
     * Output: "Obs & Gynae"
     */
    public function convertHtmlEntities(string $text): string
    {

        //convert &amp and any other html entities to normal text
        // the string looks like Obs&ampGynae
        //convert to Obs&Gynae to Obs & Gynae
        $text = str_replace(['&amp','&amp;'], ' & ', $text);
        // convert \/ to &
        $text = str_replace('\/', ' & ', $text);
        // convert multiple spaces to single space
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
