<template>
    <div class="card p-4">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">Scoreboard</h2>
            <div v-if="frozen" class="text-sm text-yellow-600 font-medium">
                <span class="animate-pulse">Frozen</span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="scoreboard-table">
                <thead>
                    <tr class="scoreboard-header">
                        <th class="scoreboard-rank p-2">#</th>
                        <th class="text-left p-2">Team</th>
                        <th class="text-center p-2 w-16">Solved</th>
                        <th class="text-center p-2 w-20">Time</th>
                        <th
                            v-for="problem in problems"
                            :key="problem.id"
                            class="scoreboard-problem p-2"
                            :style="{ backgroundColor: problem.color_hex || '#f3f4f6' }"
                        >
                            {{ problem.short_name }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in scoreboard"
                        :key="entry.user.id"
                        class="scoreboard-row"
                    >
                        <td class="scoreboard-rank p-2">{{ entry.rank }}</td>
                        <td class="scoreboard-team p-2">{{ entry.user.name }}</td>
                        <td class="text-center p-2 font-bold">{{ entry.problems_solved }}</td>
                        <td class="text-center p-2">{{ entry.total_time }}</td>
                        <td
                            v-for="problem in problems"
                            :key="problem.id"
                            class="scoreboard-problem p-2"
                            :class="getProblemClass(entry, problem.id)"
                        >
                            {{ getProblemDisplay(entry, problem.id) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="text-xs text-gray-500 mt-4">
            Last updated: {{ updatedAt }}
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
            default: 30000,
        },
    },

    data() {
        return {
            contest: null,
            problems: [],
            scoreboard: [],
            frozen: false,
            updatedAt: null,
            loading: false,
        };
    },

    mounted() {
        this.fetchScoreboard();
        this.startAutoRefresh();
    },

    beforeUnmount() {
        this.stopAutoRefresh();
    },

    methods: {
        async fetchScoreboard() {
            this.loading = true;
            try {
                const response = await axios.get(`/scoreboard/${this.contestId}`);
                this.contest = response.data.contest;
                this.problems = response.data.problems;
                this.scoreboard = response.data.scoreboard;
                this.frozen = response.data.contest.is_frozen;
                this.updatedAt = new Date(response.data.updated_at).toLocaleString();
            } catch (error) {
                console.error('Failed to fetch scoreboard:', error);
            } finally {
                this.loading = false;
            }
        },

        getProblemData(entry, problemId) {
            return entry.problems.find((p) => p.problem_id === problemId);
        },

        getProblemClass(entry, problemId) {
            const problem = this.getProblemData(entry, problemId);
            if (!problem) return '';

            if (problem.is_first_solver) return 'problem-first-solver';
            if (problem.is_solved) return 'problem-solved';
            if (problem.attempts > 0) return 'problem-attempted';
            return '';
        },

        getProblemDisplay(entry, problemId) {
            const problem = this.getProblemData(entry, problemId);
            if (!problem || problem.attempts === 0) return '';

            if (problem.is_solved) {
                const tries = problem.attempts > 1 ? `+${problem.attempts - 1}` : '';
                return `${problem.solved_time}${tries}`;
            }

            return `-${problem.attempts}`;
        },

        startAutoRefresh() {
            this.refreshTimer = setInterval(() => {
                this.fetchScoreboard();
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
