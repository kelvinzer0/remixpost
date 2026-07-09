<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Build context about today, yesterday, and day after tomorrow.
 *
 * Used by AICaptionService to give the AI model awareness of:
 *   - What day of week it is (Monday = fresh start, Friday = weekend vibe)
 *   - What holidays/events are today, yesterday, and day after tomorrow
 *   - Religious/national/regional context
 *   - Time of day (morning/afternoon/evening)
 *
 * This is a PHP mirror of resources/js/data/indonesianHolidays.js — keep
 * them in sync when adding new holidays.
 *
 * @license Apache-2.0
 */
class HolidayContextService
{
    /**
     * Indonesian holidays — 3 categories:
     *   - 'religious': year-specific (must update yearly)
     *   - 'national': recurring annual
     *   - 'regional': HUT kabupaten/kota Jatim, recurring annual
     */
    private const HOLIDAYS = [
        // === HARI BESAR KEAGAMAAN 2025 ===
        ['month' => 1, 'day' => 27, 'year' => 2025, 'name' => "Isra Mi'raj Nabi Muhammad SAW", 'type' => 'religious'],
        ['month' => 1, 'day' => 29, 'year' => 2025, 'name' => 'Tahun Baru Imlek', 'type' => 'religious'],
        ['month' => 3, 'day' => 29, 'year' => 2025, 'name' => 'Hari Raya Nyepi', 'type' => 'religious'],
        ['month' => 3, 'day' => 31, 'year' => 2025, 'name' => 'Hari Raya Idul Fitri', 'type' => 'religious'],
        ['month' => 4, 'day' => 18, 'year' => 2025, 'name' => 'Wafat Yesus Kristus', 'type' => 'religious'],
        ['month' => 4, 'day' => 20, 'year' => 2025, 'name' => 'Kebangkitan Yesus Kristus', 'type' => 'religious'],
        ['month' => 5, 'day' => 12, 'year' => 2025, 'name' => 'Hari Raya Waisak', 'type' => 'religious'],
        ['month' => 6, 'day' => 6, 'year' => 2025, 'name' => 'Hari Raya Idul Adha', 'type' => 'religious'],
        ['month' => 6, 'day' => 27, 'year' => 2025, 'name' => 'Tahun Baru Hijriah', 'type' => 'religious'],
        ['month' => 9, 'day' => 5, 'year' => 2025, 'name' => 'Maulid Nabi Muhammad SAW', 'type' => 'religious'],

        // === HARI BESAR NASIONAL (recurring) ===
        ['month' => 1, 'day' => 1, 'name' => 'Tahun Baru Masehi', 'type' => 'national'],
        ['month' => 1, 'day' => 25, 'name' => 'Hari Gizi Nasional', 'type' => 'national'],
        ['month' => 2, 'day' => 9, 'name' => 'Hari Pers Nasional', 'type' => 'national'],
        ['month' => 3, 'day' => 1, 'name' => 'Hari Kehakiman Nasional', 'type' => 'national'],
        ['month' => 3, 'day' => 6, 'name' => 'Hari Komando Strategis Angkatan Darat (Kostrad)', 'type' => 'national'],
        ['month' => 3, 'day' => 8, 'name' => 'Hari Perempuan Internasional', 'type' => 'national'],
        ['month' => 3, 'day' => 9, 'name' => 'Hari Musik Nasional', 'type' => 'national'],
        ['month' => 3, 'day' => 11, 'name' => 'Hari Surat Perintah Sebelas Maret (Supersemar)', 'type' => 'national'],
        ['month' => 3, 'day' => 21, 'name' => 'Hari Puisi Sedunia', 'type' => 'national'],
        ['month' => 3, 'day' => 23, 'name' => 'Hari Meteorologi Sedunia', 'type' => 'national'],
        ['month' => 4, 'day' => 6, 'name' => 'Hari Nelayan Nasional', 'type' => 'national'],
        ['month' => 4, 'day' => 7, 'name' => 'Hari Kesehatan Sedunia', 'type' => 'national'],
        ['month' => 4, 'day' => 9, 'name' => 'Hari Penerbangan Nasional', 'type' => 'national'],
        ['month' => 4, 'day' => 21, 'name' => 'Hari Kartini', 'type' => 'national'],
        ['month' => 4, 'day' => 24, 'name' => 'Hari Angkutan Nasional', 'type' => 'national'],
        ['month' => 4, 'day' => 27, 'name' => 'Hari Lembaga Pemasyarakatan Indonesia', 'type' => 'national'],
        ['month' => 5, 'day' => 1, 'name' => 'Hari Buruh Internasional', 'type' => 'national'],
        ['month' => 5, 'day' => 2, 'name' => 'Hari Pendidikan Nasional', 'type' => 'national'],
        ['month' => 5, 'day' => 5, 'name' => 'Hari Bidan Nasional', 'type' => 'national'],
        ['month' => 5, 'day' => 20, 'name' => 'Hari Kebangkitan Nasional', 'type' => 'national'],
        ['month' => 6, 'day' => 1, 'name' => 'Hari Lahir Pancasila', 'type' => 'national'],
        ['month' => 6, 'day' => 22, 'name' => 'Hari Ulang Tahun Kota Jakarta', 'type' => 'national'],
        ['month' => 7, 'day' => 22, 'name' => 'Hari Kejaksaan', 'type' => 'national'],
        ['month' => 8, 'day' => 10, 'name' => 'Hari Veteran Nasional', 'type' => 'national'],
        ['month' => 8, 'day' => 14, 'name' => 'Hari Pramuka', 'type' => 'national'],
        ['month' => 8, 'day' => 17, 'name' => 'Hari Kemerdekaan Indonesia', 'type' => 'national'],
        ['month' => 8, 'day' => 19, 'name' => 'Hari Departemen Luar Negeri Indonesia', 'type' => 'national'],
        ['month' => 8, 'day' => 21, 'name' => 'Hari Maritim Nasional', 'type' => 'national'],
        ['month' => 9, 'day' => 17, 'name' => 'Hari Palang Merah Indonesia', 'type' => 'national'],
        ['month' => 9, 'day' => 24, 'name' => 'Hari Tani Nasional', 'type' => 'national'],
        ['month' => 9, 'day' => 28, 'name' => 'Hari Kereta Api', 'type' => 'national'],
        ['month' => 9, 'day' => 29, 'name' => 'Hari Sarjana Nasional', 'type' => 'national'],
        ['month' => 10, 'day' => 1, 'name' => 'Hari Kesaktian Pancasila', 'type' => 'national'],
        ['month' => 10, 'day' => 5, 'name' => 'Hari Tentara Nasional Indonesia (TNI)', 'type' => 'national'],
        ['month' => 10, 'day' => 16, 'name' => 'Hari Pangan Sedunia', 'type' => 'national'],
        ['month' => 10, 'day' => 22, 'name' => 'Hari Santri Nasional', 'type' => 'national'],
        ['month' => 10, 'day' => 24, 'name' => 'Hari Dokter Nasional', 'type' => 'national'],
        ['month' => 10, 'day' => 28, 'name' => 'Hari Sumpah Pemuda', 'type' => 'national'],
        ['month' => 10, 'day' => 30, 'name' => 'Hari Keuangan', 'type' => 'national'],
        ['month' => 11, 'day' => 10, 'name' => 'Hari Pahlawan', 'type' => 'national'],
        ['month' => 11, 'day' => 12, 'name' => 'Hari Kesehatan Nasional', 'type' => 'national'],
        ['month' => 11, 'day' => 14, 'name' => 'Hari Brigade Mobil (Brimob)', 'type' => 'national'],
        ['month' => 12, 'day' => 12, 'name' => 'Hari Transmigrasi', 'type' => 'national'],
        ['month' => 12, 'day' => 22, 'name' => 'Hari Ibu', 'type' => 'national'],

        // === HUT KABUPATEN/KOTA JATIM (recurring) ===
        ['month' => 1, 'day' => 1, 'name' => 'HUT Kabupaten Jember', 'type' => 'regional'],
        ['month' => 1, 'day' => 31, 'name' => 'HUT Kabupaten Sidoarjo', 'type' => 'regional'],
        ['month' => 2, 'day' => 8, 'name' => 'HUT Kota Pasuruan', 'type' => 'regional'],
        ['month' => 2, 'day' => 19, 'name' => 'HUT Kabupaten Pacitan', 'type' => 'regional'],
        ['month' => 3, 'day' => 9, 'name' => 'HUT Kabupaten Gresik', 'type' => 'regional'],
        ['month' => 3, 'day' => 25, 'name' => 'HUT Kabupaten Kediri', 'type' => 'regional'],
        ['month' => 4, 'day' => 1, 'name' => 'HUT Kota Blitar', 'type' => 'regional'],
        ['month' => 4, 'day' => 1, 'name' => 'HUT Kota Malang', 'type' => 'regional'],
        ['month' => 4, 'day' => 10, 'name' => 'HUT Kabupaten Nganjuk', 'type' => 'regional'],
        ['month' => 4, 'day' => 18, 'name' => 'HUT Kabupaten Probolinggo', 'type' => 'regional'],
        ['month' => 5, 'day' => 9, 'name' => 'HUT Kabupaten Mojokerto', 'type' => 'regional'],
        ['month' => 5, 'day' => 26, 'name' => 'HUT Kabupaten Lamongan', 'type' => 'regional'],
        ['month' => 5, 'day' => 31, 'name' => 'HUT Kota Surabaya', 'type' => 'regional'],
        ['month' => 6, 'day' => 20, 'name' => 'HUT Kota Mojokerto', 'type' => 'regional'],
        ['month' => 6, 'day' => 20, 'name' => 'HUT Kota Madiun', 'type' => 'regional'],
        ['month' => 7, 'day' => 7, 'name' => 'HUT Kabupaten Ngawi', 'type' => 'regional'],
        ['month' => 7, 'day' => 18, 'name' => 'HUT Kabupaten Madiun', 'type' => 'regional'],
        ['month' => 7, 'day' => 27, 'name' => 'HUT Kota Kediri', 'type' => 'regional'],
        ['month' => 8, 'day' => 5, 'name' => 'HUT Kabupaten Blitar', 'type' => 'regional'],
        ['month' => 8, 'day' => 11, 'name' => 'HUT Kabupaten Ponorogo', 'type' => 'regional'],
        ['month' => 8, 'day' => 15, 'name' => 'HUT Kabupaten Situbondo', 'type' => 'regional'],
        ['month' => 8, 'day' => 17, 'name' => 'HUT Kabupaten Bondowoso', 'type' => 'regional'],
        ['month' => 8, 'day' => 31, 'name' => 'HUT Kabupaten Trenggalek', 'type' => 'regional'],
        ['month' => 9, 'day' => 4, 'name' => 'HUT Kota Probolinggo', 'type' => 'regional'],
        ['month' => 9, 'day' => 18, 'name' => 'HUT Kabupaten Pasuruan', 'type' => 'regional'],
        ['month' => 10, 'day' => 12, 'name' => 'HUT Kabupaten Magetan', 'type' => 'regional'],
        ['month' => 10, 'day' => 17, 'name' => 'HUT Kota Batu', 'type' => 'regional'],
        ['month' => 10, 'day' => 20, 'name' => 'HUT Kabupaten Bojonegoro', 'type' => 'regional'],
        ['month' => 10, 'day' => 21, 'name' => 'HUT Kabupaten Jombang', 'type' => 'regional'],
        ['month' => 10, 'day' => 24, 'name' => 'HUT Kabupaten Bangkalan', 'type' => 'regional'],
        ['month' => 10, 'day' => 31, 'name' => 'HUT Kabupaten Sumenep', 'type' => 'regional'],
        ['month' => 11, 'day' => 3, 'name' => 'HUT Kabupaten Pamekasan', 'type' => 'regional'],
        ['month' => 11, 'day' => 12, 'name' => 'HUT Kabupaten Tuban', 'type' => 'regional'],
        ['month' => 11, 'day' => 18, 'name' => 'HUT Kabupaten Tulungagung', 'type' => 'regional'],
        ['month' => 11, 'day' => 28, 'name' => 'HUT Kabupaten Malang', 'type' => 'regional'],
        ['month' => 12, 'day' => 15, 'name' => 'HUT Kabupaten Lumajang', 'type' => 'regional'],
        ['month' => 12, 'day' => 18, 'name' => 'HUT Kabupaten Banyuwangi', 'type' => 'regional'],
        ['month' => 12, 'day' => 23, 'name' => 'HUT Kabupaten Sampang', 'type' => 'regional'],
    ];

    private const TYPE_LABELS = [
        'religious' => 'Hari Besar Keagamaan',
        'national' => 'Hari Besar Nasional',
        'regional' => 'HUT Kabupaten/Kota',
    ];

    /**
     * Get holidays for a specific date (could be multiple — e.g. 1 Januari = Tahun Baru + HUT Jember).
     */
    public static function getHolidaysForDate(Carbon $date): array
    {
        $day = $date->day;
        $month = $date->month;
        $year = $date->year;

        $result = [];
        foreach (self::HOLIDAYS as $h) {
            if ($h['month'] !== $month || $h['day'] !== $day) continue;
            if (isset($h['year']) && $h['year'] !== $year) continue;
            $result[] = $h;
        }
        return $result;
    }

    /**
     * Build a human-readable context string for AI prompt.
     *
     * Structure (mimicking how a human thinks):
     *   "Hari ini Senin, 21 April 2025. Hari Kartini — momen emansipasi & perempuan.
     *    Kemarin Minggu, 20 April — tidak ada event khusus.
     *    Besok Selasa, 22 April — biasa.
     *    Lusa Rabu, 23 April — biasa.
     *    Suasana: awal pekan, energi fresh, hari nasional tentang perempuan."
     */
    public static function buildContext(?Carbon $targetDate = null): string
    {
        $targetDate = $targetDate ?? Carbon::now(config('app.timezone'));
        $yesterday = $targetDate->copy()->subDay();
        $tomorrow = $targetDate->copy()->addDay();
        $dayAfter = $targetDate->copy()->addDays(2);

        $lines = [];

        // === HARI INI ===
        $todayHolidays = self::getHolidaysForDate($targetDate);
        $lines[] = '## HARI INI';
        $lines[] = sprintf(
            'Tanggal: %s, %s',
            $targetDate->isoFormat('dddd, D MMMM YYYY'),
            self::getTimeOfDayVibe($targetDate)
        );
        if (empty($todayHolidays)) {
            $lines[] = 'Tidak ada hari besar — hari biasa.';
        } else {
            foreach ($todayHolidays as $h) {
                $lines[] = sprintf('• %s (%s)', $h['name'], self::TYPE_LABELS[$h['type']] ?? $h['type']);
            }
            $lines[] = 'Momen: ' . self::getHolidayVibe($todayHolidays);
        }

        // === KEMARIN (recap) ===
        $yesterdayHolidays = self::getHolidaysForDate($yesterday);
        $lines[] = '';
        $lines[] = '## KEMARIN';
        $lines[] = $yesterday->isoFormat('dddd, D MMMM YYYY');
        if (empty($yesterdayHolidays)) {
            $lines[] = 'Hari biasa — mungkin masih ada hangat dari weekend/aktivitas kemarin.';
        } else {
            foreach ($yesterdayHolidays as $h) {
                $lines[] = sprintf('• %s (baru lewat — bisa di-recap atau di-follow up)', $h['name']);
            }
        }

        // === BESOK & LUSA ===
        $tomorrowHolidays = self::getHolidaysForDate($tomorrow);
        $dayAfterHolidays = self::getHolidaysForDate($dayAfter);

        $lines[] = '';
        $lines[] = '## BESOK & LUSA';
        $lines[] = 'Besok ' . $tomorrow->isoFormat('dddd, D MMMM YYYY') . ': '
            . (empty($tomorrowHolidays) ? 'hari biasa' : implode(', ', array_map(fn($h) => $h['name'], $tomorrowHolidays)));
        $lines[] = 'Lusa ' . $dayAfter->isoFormat('dddd, D MMMM YYYY') . ': '
            . (empty($dayAfterHolidays) ? 'hari biasa' : implode(', ', array_map(fn($h) => $h['name'], $dayAfterHolidays)));

        // If tomorrow/lusa has holiday, suggest "persiapan" angle
        if (!empty($tomorrowHolidays) || !empty($dayAfterHolidays)) {
            $upcoming = array_merge($tomorrowHolidays, $dayAfterHolidays);
            $lines[] = 'Sudut pandang opsional: "persiapan menyambut " . ' . implode(', ', array_map(fn($h) => $h['name'], $upcoming));
        }

        // === SUASANA UMUM ===
        $lines[] = '';
        $lines[] = '## SUASANA';
        $lines[] = self::getWeekVibe($targetDate);

        return implode("\n", $lines);
    }

    /**
     * Get time-of-day vibe (morning/afternoon/evening).
     */
    private static function getTimeOfDayVibe(Carbon $date): string
    {
        $hour = (int) $date->format('H');
        if ($hour < 11) return 'Pagi (energi awal hari — fresh, semangat baru)';
        if ($hour < 15) return 'Siang (jam makan siang — momen relaksasi singkat)';
        if ($hour < 18) return 'Sore (transisi ke sore — energi mulai turun, refleksi)';
        if ($hour < 22) return 'Malam (waktu relaksasi/hiburan — audience lebih santai)';
        return 'Malam larut (audiens sedikit, tapi yang aktif biasanya committed)';
    }

    /**
     * Get the "vibe" or angle for a set of holidays.
     */
    private static function getHolidayVibe(array $holidays): string
    {
        $vibes = [];
        foreach ($holidays as $h) {
            $name = strtolower($h['name']);
            if (str_contains($name, 'kartini')) $vibes[] = 'emansipasi, perempuan hebat, equality';
            elseif (str_contains($name, 'kemerdekaan')) $vibes[] = 'nasionalisme, merdeka, bangga Indonesia';
            elseif (str_contains($name, 'idul fitri') || str_contains($name, 'lebaran')) $vibes[] = 'maaf lahir batin, kemenangan, keluarga';
            elseif (str_contains($name, 'idul adha')) $vibes[] = 'berbagi, qurban, ketulusan';
            elseif (str_contains($name, 'natal') || str_contains($name, 'yesus') || str_contains($name, 'kebangkitan')) $vibes[] = 'kedamaian, harapan, keluarga';
            elseif (str_contains($name, 'nyepi')) $vibes[] = 'keheningan, intropeksi, self reflection';
            elseif (str_contains($name, 'waisak')) $vibes[] = 'kedamaian, pencerahan spiritual';
            elseif (str_contains($name, 'imlek')) $vibes[] = 'rezeki, keluarga, tradisi tionghoa';
            elseif (str_contains($name, 'maulid')) $vibes[] = 'teladan nabi, spiritual';
            elseif (str_contains($name, 'isra') || str_contains($name, "mi'raj")) $vibes[] = 'perjalanan spiritual, keteguhan';
            elseif (str_contains($name, 'hijriah')) $vibes[] = 'tahun baru hijriah, refleksi, muhasabah';
            elseif (str_contains($name, 'pahlawan')) $vibes[] = 'mengenang jasa pahlawan, semangat juang';
            elseif (str_contains($name, 'sumpah pemuda')) $vibes[] = 'pemuda, persatuan, semangat';
            elseif (str_contains($name, 'ibu')) $vibes[] = 'menghargai ibu, kasih sayang';
            elseif (str_contains($name, 'buruh')) $vibes[] = 'perjuangan buruh, hak pekerja';
            elseif (str_contains($name, 'pendidikan')) $vibes[] = 'pentingnya pendidikan';
            elseif (str_contains($name, 'kebangkitan nasional')) $vibes[] = 'semangat bangkit, progress';
            elseif (str_contains($name, 'pancasila')) $vibes[] = 'dasar negara, ideologi';
            elseif (str_contains($name, 'pramuka')) $vibes[] = 'kepramukaan, anak muda, alam';
            elseif (str_contains($name, 'santri')) $vibes[] = 'santri, pesantren, tradisi islami';
            elseif (str_contains($name, 'kartini')) $vibes[] = 'emansipasi, perempuan hebat';
            elseif (str_contains($name, 'hut')) $vibes[] = 'ulang tahun daerah, bangga lokal, komunitas';
            else $vibes[] = 'momentum spesial';
        }
        return implode('; ', array_unique($vibes));
    }

    /**
     * Get the "vibe" of a weekday.
     * Memetakan cara orang berfikir tentang hari dalam seminggu.
     */
    private static function getWeekVibe(Carbon $date): string
    {
        $dayOfWeek = (int) $date->format('N'); // 1=Mon, 7=Sun

        return match ($dayOfWeek) {
            1 => 'Senin — awal pekan. Orang semangat baru, target mingguan, fresh start. Cocok untuk content motivasi/perencanaan.',
            2 => 'Selasa — hari produktif. Momentum kerja sudah jalan, fokus tinggi.',
            3 => 'Rabu — "hump day". Pertengahan pekan, orang butuh dorongan untuk finishing. Banyak yang capek.',
            4 => 'Kamis — mendekati weekend. Antisipasi, semangat naik lagi.',
            5 => 'Jumat — vibes weekend mulai terasa. Orang santai, excited. Cocok content ringan/fun.',
            6 => 'Sabtu — weekend. Santai, hiburan, family time. Audience lebih lama scroll.',
            7 => 'Minggu — refleksi, persiapan minggu depan. Cocok content "weekly recap" atau "Monday prep".',
            default => 'Hari biasa',
        };
    }
}
