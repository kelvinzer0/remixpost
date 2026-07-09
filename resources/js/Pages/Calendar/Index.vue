<script setup>
import { Head, Link } from '@inertiajs/vue3';
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

// Get holidays for a specific date (may return multiple — e.g. national + regional)
const getHolidaysForDate = (date) => {
    return getHolidaysForDay(date);
};

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

// Indonesian weekday names
const weekdayNames = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
</script>

<template>
    <AppLayout>
        <template #header>Calendar</template>
        <Head title="Calendar" />

        <div class="bg-white rounded-lg shadow p-6">
            <!-- Month navigation -->
            <div class="flex items-center justify-between mb-6">
                <Link :href="prevMonth()"
                    class="px-3 py-1.5 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    ← Sebelumnya
                </Link>
                <h2 class="text-lg font-semibold text-gray-900">{{ monthName }}</h2>
                <Link :href="nextMonth()"
                    class="px-3 py-1.5 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    Berikutnya →
                </Link>
            </div>

            <!-- Legend -->
            <div class="mb-4 flex flex-wrap items-center gap-3 text-xs">
                <span class="text-gray-500 font-medium">Hari Besar:</span>
                <span v-for="(meta, type) in HOLIDAY_TYPE_META" :key="type"
                    class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full border"
                    :class="meta.badgeClass">
                    <span>{{ meta.icon }}</span>
                    <span>{{ meta.label }}</span>
                </span>
            </div>

            <!-- Weekday headers -->
            <div class="grid grid-cols-7 gap-1 mb-2">
                <div v-for="day in weekdayNames" :key="day"
                    class="text-center text-xs font-medium text-gray-500 uppercase py-2">
                    {{ day }}
                </div>
            </div>

            <!-- Calendar grid -->
            <div class="grid grid-cols-7 gap-1">
                <div v-for="(day, index) in days" :key="index"
                    class="min-h-[110px] p-1.5 border border-gray-100 rounded-md flex flex-col"
                    :class="{
                        'bg-gray-50': !isCurrentMonth(day),
                        'bg-brand-50': isToday(day),
                    }">
                    <!-- Date number + holiday dot indicator -->
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-xs font-medium text-gray-700">{{ day.getDate() }}</p>
                        <!-- Small colored dots if holidays exist (compact indicator) -->
                        <div v-if="getHolidaysForDate(day).length > 0" class="flex gap-0.5">
                            <span v-for="(h, i) in getHolidaysForDate(day)" :key="i"
                                class="w-1.5 h-1.5 rounded-full"
                                :class="HOLIDAY_TYPE_META[h.type].dotClass"
                                :title="h.name"></span>
                        </div>
                    </div>

                    <!-- Holiday badges -->
                    <div class="space-y-0.5 mb-1">
                        <div v-for="(h, i) in getHolidaysForDate(day)" :key="'h'+i"
                            class="px-1 py-0.5 text-[9px] font-medium rounded border truncate leading-tight"
                            :class="HOLIDAY_TYPE_META[h.type].badgeClass"
                            :title="h.name">
                            {{ HOLIDAY_TYPE_META[h.type].icon }} {{ h.name }}
                        </div>
                    </div>

                    <!-- Posts -->
                    <div class="space-y-1 flex-1 overflow-hidden">
                        <Link v-for="post in getPostsForDay(day)" :key="post.id"
                            :href="`/posts/${post.id}`"
                            class="block px-1.5 py-1 text-xs text-white rounded truncate"
                            :class="statusColors[post.status] || 'bg-gray-400'">
                            {{ post.content.substring(0, 30) }}{{ post.content.length > 30 ? '…' : '' }}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
