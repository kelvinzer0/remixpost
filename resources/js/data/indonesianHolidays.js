/**
 * Indonesian holidays data — religious, national, and East Java regency/city anniversaries.
 *
 * Categories:
 *   - 'religious': Hari Besar Keagamaan (year-specific, may change yearly)
 *   - 'national': Hari Besar Nasional (recurring annual)
 *   - 'regional': Ulang Tahun Kabupaten/Kota Jawa Timur (recurring annual)
 *
 * For year-specific holidays (religious), the `year` field must match.
 * For recurring holidays, omit `year` — they show every year on the same month/day.
 *
 * Source: User-provided list (2025 religious holidays, recurring national/regional).
 * Religious holidays should be updated yearly as Islamic/Hindu/Buddhist dates shift.
 */

export const INDONESIAN_HOLIDAYS = [
    // ============================================
    // HARI BESAR KEAGAMAAN (Year-specific — 2025)
    // ============================================
    { month: 1, day: 27, year: 2025, name: "Isra Mi'raj Nabi Muhammad SAW", type: 'religious' },
    { month: 1, day: 29, year: 2025, name: 'Tahun Baru Imlek', type: 'religious' },
    { month: 3, day: 29, year: 2025, name: 'Hari Raya Nyepi', type: 'religious' },
    { month: 3, day: 31, year: 2025, name: 'Hari Raya Idul Fitri', type: 'religious' },
    { month: 4, day: 18, year: 2025, name: 'Wafat Yesus Kristus', type: 'religious' },
    { month: 4, day: 20, year: 2025, name: 'Kebangkitan Yesus Kristus', type: 'religious' },
    { month: 5, day: 12, year: 2025, name: 'Hari Raya Waisak', type: 'religious' },
    { month: 6, day: 6, year: 2025, name: 'Hari Raya Idul Adha', type: 'religious' },
    { month: 6, day: 27, year: 2025, name: 'Tahun Baru Hijriah', type: 'religious' },
    { month: 9, day: 5, year: 2025, name: 'Maulid Nabi Muhammad SAW', type: 'religious' },

    // ============================================
    // HARI BESAR NASIONAL (Recurring annual)
    // ============================================
    { month: 1, day: 1, name: 'Tahun Baru Masehi', type: 'national' },
    { month: 1, day: 25, name: 'Hari Gizi Nasional', type: 'national' },
    { month: 2, day: 9, name: 'Hari Pers Nasional', type: 'national' },
    { month: 3, day: 1, name: 'Hari Kehakiman Nasional', type: 'national' },
    { month: 3, day: 6, name: 'Hari Komando Strategis Angkatan Darat (Kostrad)', type: 'national' },
    { month: 3, day: 8, name: 'Hari Perempuan Internasional', type: 'national' },
    { month: 3, day: 9, name: 'Hari Musik Nasional', type: 'national' },
    { month: 3, day: 11, name: 'Hari Surat Perintah Sebelas Maret (Supersemar)', type: 'national' },
    { month: 3, day: 21, name: 'Hari Puisi Sedunia', type: 'national' },
    { month: 3, day: 23, name: 'Hari Meteorologi Sedunia', type: 'national' },
    { month: 4, day: 6, name: 'Hari Nelayan Nasional', type: 'national' },
    { month: 4, day: 7, name: 'Hari Kesehatan Sedunia', type: 'national' },
    { month: 4, day: 9, name: 'Hari Penerbangan Nasional', type: 'national' },
    { month: 4, day: 21, name: 'Hari Kartini', type: 'national' },
    { month: 4, day: 24, name: 'Hari Angkutan Nasional', type: 'national' },
    { month: 4, day: 27, name: 'Hari Lembaga Pemasyarakatan Indonesia', type: 'national' },
    { month: 5, day: 1, name: 'Hari Buruh Internasional', type: 'national' },
    { month: 5, day: 2, name: 'Hari Pendidikan Nasional', type: 'national' },
    { month: 5, day: 5, name: 'Hari Bidan Nasional', type: 'national' },
    { month: 5, day: 20, name: 'Hari Kebangkitan Nasional', type: 'national' },
    { month: 6, day: 1, name: 'Hari Lahir Pancasila', type: 'national' },
    { month: 6, day: 22, name: 'Hari Ulang Tahun Kota Jakarta', type: 'national' },
    { month: 7, day: 22, name: 'Hari Kejaksaan', type: 'national' },
    { month: 8, day: 10, name: 'Hari Veteran Nasional', type: 'national' },
    { month: 8, day: 14, name: 'Hari Pramuka', type: 'national' },
    { month: 8, day: 17, name: 'Hari Kemerdekaan Indonesia', type: 'national' },
    { month: 8, day: 19, name: 'Hari Departemen Luar Negeri Indonesia', type: 'national' },
    { month: 8, day: 21, name: 'Hari Maritim Nasional', type: 'national' },
    { month: 9, day: 17, name: 'Hari Palang Merah Indonesia', type: 'national' },
    { month: 9, day: 24, name: 'Hari Tani Nasional', type: 'national' },
    { month: 9, day: 28, name: 'Hari Kereta Api', type: 'national' },
    { month: 9, day: 29, name: 'Hari Sarjana Nasional', type: 'national' },
    { month: 10, day: 1, name: 'Hari Kesaktian Pancasila', type: 'national' },
    { month: 10, day: 5, name: 'Hari Tentara Nasional Indonesia (TNI)', type: 'national' },
    { month: 10, day: 16, name: 'Hari Pangan Sedunia', type: 'national' },
    { month: 10, day: 22, name: 'Hari Santri Nasional', type: 'national' },
    { month: 10, day: 24, name: 'Hari Dokter Nasional', type: 'national' },
    { month: 10, day: 28, name: 'Hari Sumpah Pemuda', type: 'national' },
    { month: 10, day: 30, name: 'Hari Keuangan', type: 'national' },
    { month: 11, day: 10, name: 'Hari Pahlawan', type: 'national' },
    { month: 11, day: 12, name: 'Hari Kesehatan Nasional', type: 'national' },
    { month: 11, day: 14, name: 'Hari Brigade Mobil (Brimob)', type: 'national' },
    { month: 12, day: 12, name: 'Hari Transmigrasi', type: 'national' },
    { month: 12, day: 22, name: 'Hari Ibu', type: 'national' },

    // ============================================
    // ULANG TAHUN KABUPATEN/KOTA JAWA TIMUR (Recurring annual)
    // ============================================
    { month: 1, day: 1, name: 'HUT Kabupaten Jember', type: 'regional' },
    { month: 1, day: 31, name: 'HUT Kabupaten Sidoarjo', type: 'regional' },
    { month: 2, day: 8, name: 'HUT Kota Pasuruan', type: 'regional' },
    { month: 2, day: 19, name: 'HUT Kabupaten Pacitan', type: 'regional' },
    { month: 3, day: 9, name: 'HUT Kabupaten Gresik', type: 'regional' },
    { month: 3, day: 25, name: 'HUT Kabupaten Kediri', type: 'regional' },
    { month: 4, day: 1, name: 'HUT Kota Blitar', type: 'regional' },
    { month: 4, day: 1, name: 'HUT Kota Malang', type: 'regional' },
    { month: 4, day: 10, name: 'HUT Kabupaten Nganjuk', type: 'regional' },
    { month: 4, day: 18, name: 'HUT Kabupaten Probolinggo', type: 'regional' },
    { month: 5, day: 9, name: 'HUT Kabupaten Mojokerto', type: 'regional' },
    { month: 5, day: 26, name: 'HUT Kabupaten Lamongan', type: 'regional' },
    { month: 5, day: 31, name: 'HUT Kota Surabaya', type: 'regional' },
    { month: 6, day: 20, name: 'HUT Kota Mojokerto', type: 'regional' },
    { month: 6, day: 20, name: 'HUT Kota Madiun', type: 'regional' },
    { month: 7, day: 7, name: 'HUT Kabupaten Ngawi', type: 'regional' },
    { month: 7, day: 18, name: 'HUT Kabupaten Madiun', type: 'regional' },
    { month: 7, day: 27, name: 'HUT Kota Kediri', type: 'regional' },
    { month: 8, day: 5, name: 'HUT Kabupaten Blitar', type: 'regional' },
    { month: 8, day: 11, name: 'HUT Kabupaten Ponorogo', type: 'regional' },
    { month: 8, day: 15, name: 'HUT Kabupaten Situbondo', type: 'regional' },
    { month: 8, day: 17, name: 'HUT Kabupaten Bondowoso', type: 'regional' },
    { month: 8, day: 31, name: 'HUT Kabupaten Trenggalek', type: 'regional' },
    { month: 9, day: 4, name: 'HUT Kota Probolinggo', type: 'regional' },
    { month: 9, day: 18, name: 'HUT Kabupaten Pasuruan', type: 'regional' },
    { month: 10, day: 12, name: 'HUT Kabupaten Magetan', type: 'regional' },
    { month: 10, day: 17, name: 'HUT Kota Batu', type: 'regional' },
    { month: 10, day: 20, name: 'HUT Kabupaten Bojonegoro', type: 'regional' },
    { month: 10, day: 21, name: 'HUT Kabupaten Jombang', type: 'regional' },
    { month: 10, day: 24, name: 'HUT Kabupaten Bangkalan', type: 'regional' },
    { month: 10, day: 31, name: 'HUT Kabupaten Sumenep', type: 'regional' },
    { month: 11, day: 3, name: 'HUT Kabupaten Pamekasan', type: 'regional' },
    { month: 11, day: 12, name: 'HUT Kabupaten Tuban', type: 'regional' },
    { month: 11, day: 18, name: 'HUT Kabupaten Tulungagung', type: 'regional' },
    { month: 11, day: 28, name: 'HUT Kabupaten Malang', type: 'regional' },
    { month: 12, day: 15, name: 'HUT Kabupaten Lumajang', type: 'regional' },
    { month: 12, day: 18, name: 'HUT Kabupaten Banyuwangi', type: 'regional' },
    { month: 12, day: 23, name: 'HUT Kabupaten Sampang', type: 'regional' },
];

/**
 * Get all holidays that fall on a specific date.
 * Matches by month + day, and for year-specific holidays also by year.
 *
 * @param {Date} date
 * @returns {Array} matching holidays (may be multiple — e.g. national + regional on same day)
 */
export function getHolidaysForDay(date) {
    const day = date.getDate();
    const month = date.getMonth() + 1; // JS months are 0-indexed
    const year = date.getFullYear();

    return INDONESIAN_HOLIDAYS.filter(h => {
        if (h.month !== month || h.day !== day) return false;
        // Year-specific holidays (religious) must match year exactly
        if (h.year && h.year !== year) return false;
        return true;
    });
}

/**
 * Holiday type metadata for UI rendering.
 * Colors chosen to be distinct from post status colors (yellow/green/red/gray).
 */
export const HOLIDAY_TYPE_META = {
    religious: {
        label: 'Hari Besar Keagamaan',
        badgeClass: 'bg-purple-100 text-purple-800 border-purple-300',
        dotClass: 'bg-purple-500',
        icon: '🕌',
    },
    national: {
        label: 'Hari Besar Nasional',
        badgeClass: 'bg-blue-100 text-blue-800 border-blue-300',
        dotClass: 'bg-blue-500',
        icon: '🇮🇩',
    },
    regional: {
        label: 'HUT Kabupaten/Kota Jatim',
        badgeClass: 'bg-emerald-100 text-emerald-800 border-emerald-300',
        dotClass: 'bg-emerald-500',
        icon: '📍',
    },
};
