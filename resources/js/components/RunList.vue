<template>
    <div class="card">
        <div class="p-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold">Submissions</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left p-3 font-medium text-gray-600">#</th>
                        <th class="text-left p-3 font-medium text-gray-600">Time</th>
                        <th class="text-left p-3 font-medium text-gray-600">Problem</th>
                        <th class="text-left p-3 font-medium text-gray-600">Language</th>
                        <th class="text-center p-3 font-medium text-gray-600">Verdict</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="run in runs"
                        :key="run.id"
                        class="border-b border-gray-100 hover:bg-gray-50"
                    >
                        <td class="p-3 font-mono">{{ run.run_number }}</td>
                        <td class="p-3 text-gray-600">{{ formatTime(run.contest_time) }}</td>
                        <td class="p-3">
                            <span
                                class="inline-flex items-center px-2 py-1 rounded text-xs font-medium"
                                :style="{ backgroundColor: run.problem?.color_hex || '#e5e7eb' }"
                            >
                                {{ run.problem?.short_name }}
                            </span>
                            <span class="ml-2 text-gray-700">{{ run.problem?.name }}</span>
                        </td>
                        <td class="p-3 text-gray-600">{{ run.language?.name }}</td>
                        <td class="p-3 text-center">
                            <span :class="getVerdictClass(run.answer)">
                                {{ run.answer?.short_name || 'Pending' }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="runs.length === 0">
                        <td colspan="5" class="p-8 text-center text-gray-500">
                            No submissions yet
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="hasMore" class="p-4 text-center border-t border-gray-200">
            <button @click="loadMore" class="btn-secondary" :disabled="loading">
                {{ loading ? 'Loading...' : 'Load More' }}
            </button>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        contestId: {
            type: Number,
            required: true,
        },
        refreshInterval: {
            type: Number,
            default: 15000,
        },
    },

    data() {
        return {
            runs: [],
            page: 1,
            hasMore: false,
            loading: false,
        };
    },

    mounted() {
        this.fetchRuns();
        this.startAutoRefresh();
    },

    beforeUnmount() {
        this.stopAutoRefresh();
    },

    methods: {
        async fetchRuns(append = false) {
            this.loading = true;
            try {
                const response = await axios.get('/runs', {
                    params: {
                        contest_id: this.contestId,
                        page: this.page,
                    },
                });

                if (append) {
                    this.runs.push(...response.data.data);
                } else {
                    this.runs = response.data.data;
                }

                this.hasMore = response.data.next_page_url !== null;
            } catch (error) {
                console.error('Failed to fetch runs:', error);
            } finally {
                this.loading = false;
            }
        },

        loadMore() {
            this.page++;
            this.fetchRuns(true);
        },

        formatTime(seconds) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = seconds % 60;
            return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        },

        getVerdictClass(answer) {
            if (!answer) return 'verdict-pending';

            const classMap = {
                AC: 'verdict-ac',
                WA: 'verdict-wa',
                TLE: 'verdict-tle',
                RE: 'verdict-re',
                CE: 'verdict-ce',
                MLE: 'verdict-re',
                PE: 'verdict-wa',
            };

            return classMap[answer.short_name] || 'verdict-pending';
        },

        startAutoRefresh() {
            this.refreshTimer = setInterval(() => {
                this.page = 1;
                this.fetchRuns();
            }, this.refreshInterval);
        },

        stopAutoRefresh() {
            if (this.refreshTimer) {
                clearInterval(this.refreshTimer);
            }
        },
    },
};
</script>
