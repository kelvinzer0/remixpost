<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { getHolidaysForDay, HOLIDAY_TYPE_META } from '@/data/indonesianHolidays';

const props = defineProps({
    posts: Array,
    currentMonth: String,
});

const statusColors = {
    scheduled: 'bg-yellow-500',
    published: 'bg-green-500',
    failed: 'bg-red-500',
    draft: 'bg-gray-400',
};

// Track which day is "expanded" (tapped on mobile) for popup details
const expandedDay = ref(null);

const toggleDay = (day) => {
    const ts = day.getTime();
    if (expandedDay.value === ts) {
        expandedDay.value = null;
    } else {
        expandedDay.value = ts;
    }
};

const isExpanded = (day) => expandedDay.value === day.getTime();

// Build calendar grid for current month
const buildCalendar = (monthStr) => {
    const [year, month] = (monthStr || new Date().toISOString().slice(0, 7)).split('-').map(Number);
    const firstDay = new Date(year, month - 1, 1);
    const lastDay = new Date(year, month, 0);
    const startDate = new Date(firstDay);
    startDate.setDate(startDate.getDate() - startDate.getDay());

    const days = [];
    const current = new Date(startDate);
    while (days.length < 42) {
        days.push(new Date(current));
        current.setDate(current.getDate() + 1);
    }
    return { days, year, month: month - 1 };
};

const { days, year, month } = buildCalendar(props.currentMonth);

const getPostsForDay = (date) => {
    return (props.posts || []).filter(post => {
        const postDate = new Date(post.scheduled_at || post.published_at);
        return postDate.getDate() === date.getDate() &&
               postDate.getMonth() === date.getMonth() &&
               postDate.getFullYear() === date.getFullYear();
    });
};

const getHolidaysForDate = (date) => {
    return getHolidaysForDay(date);
};

// Build a tooltip string for desktop hover (full holiday names + post count)
const getTooltipForDay = (date) => {
    const holidays = getHolidaysForDate(date);
    const posts = getPostsForDay(date);
    const parts = [];
    if (holidays.length > 0) {
        parts.push(...holidays.map(h => `${HOLIDAY_TYPE_META[h.type].icon} ${h.name}`));
    }
    if (posts.length > 0) {
        parts.push(`📝 ${posts.length} post terjadwal`);
    }
    return parts.length > 0 ? parts.join('\n') : '';
};

// Expanded day details (for mobile tap)
const expandedDayInfo = computed(() => {
    if (!expandedDay.value) return null;
    const day = days.find(d => d.getTime() === expandedDay.value);
    if (!day) return null;
    return {
        date: day,
        holidays: getHolidaysForDate(day),
        posts: getPostsForDay(day),
    };
});

const isCurrentMonth = (date) => date.getMonth() === month;
const isToday = (date) => {
    const today = new Date();
    return date.getDate() === today.getDate() &&
           date.getMonth() === today.getMonth() &&
           date.getFullYear() === today.getFullYear();
};

const monthName = new Date(year, month, 1).toLocaleString('id-ID', { month: 'long', year: 'numeric' });
const prevMonth = () => {
    const d = new Date(year, month - 1, 1);
    return `/calendar?month=${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};
const nextMonth = () => {
    const d = new Date(year, month + 1, 1);
    return `/calendar?month=${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

const formatDayFull = (date) => {
    return date.toLocaleString('id-ID', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
    });
};

const formatPostTime = (dateStr) => {
    return new Date(dateStr).toLocaleString('id-ID', {
        hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta'
    });
};

// Indonesian weekday names (short + single letter for very small screens)
const weekdayShort = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const weekdayMini = ['M', 'S', 'S', 'R', 'K', 'J', 'S'];
</script>

<template>
    <AppLayout>
        <template #header>Calendar</template>
        <Head title="Calendar" />

        <div class="bg-white rounded-lg shadow p-3 md:p-6">
            <!-- Month navigation -->
            <div class="flex items-center justify-between mb-4 md:mb-6">
                <Link :href="prevMonth()"
                    class="px-2 md:px-3 py-1.5 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    ← <span class="hidden sm:inline">Sebelumnya</span>
                </Link>
                <h2 class="text-base md:text-lg font-semibold text-gray-900 text-center">{{ monthName }}</h2>
                <Link :href="nextMonth()"
                    class="px-2 md:px-3 py-1.5 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    <span class="hidden sm:inline">Berikutnya</span> →
                </Link>
            </div>

            <!-- Legend (collapsible on mobile) -->
            <details class="mb-3 md:mb-4">
                <summary class="cursor-pointer text-xs text-gray-500 hover:text-gray-700 select-none">
                    📅 Kategori Hari Besar
                </summary>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    <span v-for="(meta, type) in HOLIDAY_TYPE_META" :key="type"
                        class="inline-flex items-center gap-1 px-2 py-1 rounded-full border"
                        :class="meta.badgeClass">
                        <span>{{ meta.icon }}</span>
                        <span>{{ meta.label }}</span>
                    </span>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-yellow-100 text-yellow-800 border border-yellow-300">
                        📝 Post
                    </span>
                </div>
            </details>

            <!-- Weekday headers -->
            <div class="grid grid-cols-7 gap-0.5 md:gap-1 mb-1 md:mb-2">
                <div v-for="(day, i) in weekdayShort" :key="day"
                    class="text-center text-[10px] md:text-xs font-medium text-gray-500 uppercase py-1 md:py-2">
                    <span class="hidden sm:inline">{{ day }}</span>
                    <span class="sm:hidden">{{ weekdayMini[i] }}</span>
                </div>
            </div>

            <!-- Calendar grid -->
            <div class="grid grid-cols-7 gap-0.5 md:gap-1">
                <div v-for="(day, index) in days" :key="index"
                    class="relative border rounded-sm md:rounded-md p-0.5 md:p-1.5 cursor-pointer transition"
                    :class="{
                        'bg-gray-50 border-gray-100': !isCurrentMonth(day),
                        'bg-brand-50 border-brand-200': isToday(day),
                        'bg-white border-gray-100 hover:border-gray-300': isCurrentMonth(day) && !isToday(day) && !isExpanded(day),
                        'bg-blue-50 border-blue-400 ring-1 ring-blue-300': isExpanded(day),
                    }"
                    @click="toggleDay(day)"
                    :title="getTooltipForDay(day)">
                    <!-- Date number -->
                    <p class="text-[10px] md:text-xs font-medium mb-0.5"
                        :class="isCurrentMonth(day) ? 'text-gray-700' : 'text-gray-400'">
                        {{ day.getDate() }}
                    </p>

                    <!-- MOBILE: dots only (no text) -->
                    <div class="sm:hidden flex flex-col items-center gap-0.5 min-h-[8px]">
                        <!-- Holiday dots -->
                        <div v-if="getHolidaysForDate(day).length > 0" class="flex flex-wrap gap-0.5 justify-center">
                            <span v-for="(h, i) in getHolidaysForDate(day).slice(0, 3)" :key="'mh'+i"
                                class="w-1.5 h-1.5 rounded-full"
                                :class="HOLIDAY_TYPE_META[h.type].dotClass"></span>
                        </div>
                        <!-- Post dots -->
                        <div v-if="getPostsForDay(day).length > 0" class="flex flex-wrap gap-0.5 justify-center">
                            <span v-for="(p, i) in getPostsForDay(day).slice(0, 3)" :key="'mp'+i"
                                class="w-1.5 h-1.5 rounded-full"
                                :class="statusColors[p.status] || 'bg-gray-400'"></span>
                        </div>
                    </div>

                    <!-- DESKTOP (sm+): holiday badges + post text -->
                    <div class="hidden sm:block space-y-0.5">
                        <div v-for="(h, i) in getHolidaysForDate(day)" :key="'h'+i"
                            class="px-1 py-0.5 text-[9px] font-medium rounded border truncate leading-tight"
                            :class="HOLIDAY_TYPE_META[h.type].badgeClass"
                            :title="h.name">
                            {{ HOLIDAY_TYPE_META[h.type].icon }} {{ h.name }}
                        </div>
                        <Link v-for="post in getPostsForDay(day).slice(0, 2)" :key="post.id"
                            :href="`/posts/${post.id}`"
                            @click.stop
                            class="block px-1.5 py-1 text-xs text-white rounded truncate"
                            :class="statusColors[post.status] || 'bg-gray-400'"
                            :title="post.content.substring(0, 100)">
                            {{ post.content.substring(0, 20) }}{{ post.content.length > 20 ? '…' : '' }}
                        </Link>
                        <p v-if="getPostsForDay(day).length > 2" class="text-[10px] text-gray-400 px-1">
                            +{{ getPostsForDay(day).length - 2 }} lainnya
                        </p>
                    </div>
                </div>
            </div>

            <!-- Mobile: expanded day details panel -->
            <div v-if="expandedDayInfo" class="mt-4 sm:hidden bg-gray-50 rounded-lg p-4 border border-gray-200">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-900">
                        📅 {{ formatDayFull(expandedDayInfo.date) }}
                    </h3>
                    <button @click="expandedDay = null"
                        class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Holidays for this day -->
                <div v-if="expandedDayInfo.holidays.length > 0" class="mb-3">
                    <p class="text-xs font-medium text-gray-500 uppercase mb-1">Hari Besar</p>
                    <div class="space-y-1">
                        <div v-for="(h, i) in expandedDayInfo.holidays" :key="i"
                            class="px-2 py-1.5 text-xs rounded border"
                            :class="HOLIDAY_TYPE_META[h.type].badgeClass">
                            {{ HOLIDAY_TYPE_META[h.type].icon }} {{ h.name }}
                        </div>
                    </div>
                </div>

                <!-- Posts for this day -->
                <div v-if="expandedDayInfo.posts.length > 0">
                    <p class="text-xs font-medium text-gray-500 uppercase mb-1">Posts ({{ expandedDayInfo.posts.length }})</p>
                    <div class="space-y-2">
                        <Link v-for="post in expandedDayInfo.posts" :key="post.id"
                            :href="`/posts/${post.id}`"
                            class="block p-2 bg-white rounded border border-gray-200 hover:border-brand-400">
                            <div class="flex items-center justify-between mb-1">
                                <span class="px-1.5 py-0.5 text-[10px] font-medium rounded-full capitalize text-white"
                                    :class="statusColors[post.status] || 'bg-gray-400'">
                                    {{ post.status }}
                                </span>
                                <span class="text-xs text-gray-500">{{ formatPostTime(post.scheduled_at) }}</span>
                            </div>
                            <p class="text-xs text-gray-700 line-clamp-2">{{ post.content }}</p>
                        </Link>
                    </div>
                </div>

                <p v-if="expandedDayInfo.holidays.length === 0 && expandedDayInfo.posts.length === 0"
                    class="text-sm text-gray-400 text-center py-4">
                    Tidak ada event di tanggal ini
                </p>
            </div>

            <!-- Mobile hint -->
            <p class="sm:hidden mt-3 text-center text-xs text-gray-400">
                👆 Tap tanggal untuk lihat detail
            </p>
        </div>
    </AppLayout>
</template>
