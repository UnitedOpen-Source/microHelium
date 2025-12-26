<template>
    <div class="card">
        <div class="p-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-semibold">Clarifications</h2>
            <button @click="showNewForm = true" class="btn-primary text-sm">
                Ask Question
            </button>
        </div>

        <!-- New clarification form -->
        <div v-if="showNewForm" class="p-4 bg-blue-50 border-b border-blue-200">
            <form @submit.prevent="submitClarification">
                <div class="space-y-3">
                    <div>
                        <label class="label">Problem (optional)</label>
                        <select v-model="newClarification.problem_id" class="input">
                            <option value="">General question</option>
                            <option
                                v-for="problem in problems"
                                :key="problem.id"
                                :value="problem.id"
                            >
                                {{ problem.short_name }} - {{ problem.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Question</label>
                        <textarea
                            v-model="newClarification.question"
                            class="input"
                            rows="3"
                            maxlength="2000"
                            required
                        ></textarea>
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button type="button" @click="showNewForm = false" class="btn-secondary">
                            Cancel
                        </button>
                        <button type="submit" class="btn-primary" :disabled="submitting">
                            {{ submitting ? 'Sending...' : 'Send' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Clarification list -->
        <div class="divide-y divide-gray-100">
            <div
                v-for="clar in clarifications"
                :key="clar.id"
                class="p-4"
                :class="{ 'bg-yellow-50': clar.status === 'pending' }"
            >
                <div class="flex items-start justify-between mb-2">
                    <div class="flex items-center space-x-2">
                        <span class="text-xs text-gray-500">#{{ clar.clarification_number }}</span>
                        <span
                            v-if="clar.problem"
                            class="px-2 py-0.5 bg-gray-200 rounded text-xs font-medium"
                        >
                            {{ clar.problem.short_name }}
                        </span>
                        <span
                            :class="getStatusClass(clar.status)"
                            class="px-2 py-0.5 rounded text-xs font-medium"
                        >
                            {{ getStatusLabel(clar.status) }}
                        </span>
                    </div>
                    <span class="text-xs text-gray-500">{{ formatTime(clar.contest_time) }}</span>
                </div>

                <div class="bg-gray-100 rounded p-3 mb-2">
                    <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ clar.question }}</p>
                </div>

                <div v-if="clar.answer" class="bg-green-50 rounded p-3 border-l-4 border-green-500">
                    <p class="text-sm font-medium text-green-800 mb-1">Answer:</p>
                    <p class="text-sm text-green-700 whitespace-pre-wrap">{{ clar.answer }}</p>
                </div>
            </div>

            <div v-if="clarifications.length === 0" class="p-8 text-center text-gray-500">
                No clarifications yet
            </div>
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
        problems: {
            type: Array,
            required: true,
        },
        refreshInterval: {
            type: Number,
            default: 30000,
        },
    },

    data() {
        return {
            clarifications: [],
            showNewForm: false,
            newClarification: {
                problem_id: '',
                question: '',
            },
            submitting: false,
        };
    },

    mounted() {
        this.fetchClarifications();
        this.startAutoRefresh();
    },

    beforeUnmount() {
        this.stopAutoRefresh();
    },

    methods: {
        async fetchClarifications() {
            try {
                const response = await axios.get('/clarifications', {
                    params: { contest_id: this.contestId },
                });
                this.clarifications = response.data.data;
            } catch (error) {
                console.error('Failed to fetch clarifications:', error);
            }
        },

        async submitClarification() {
            this.submitting = true;
            try {
                await axios.post('/clarifications', {
                    contest_id: this.contestId,
                    problem_id: this.newClarification.problem_id || null,
                    question: this.newClarification.question,
                });

                this.newClarification.problem_id = '';
                this.newClarification.question = '';
                this.showNewForm = false;
                this.fetchClarifications();
            } catch (error) {
                alert(error.response?.data?.error || 'Failed to submit clarification');
            } finally {
                this.submitting = false;
            }
        },

        formatTime(seconds) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
        },

        getStatusClass(status) {
            const classes = {
                pending: 'bg-yellow-100 text-yellow-800',
                answering: 'bg-blue-100 text-blue-800',
                answered: 'bg-green-100 text-green-800',
                broadcast_site: 'bg-purple-100 text-purple-800',
                broadcast_all: 'bg-purple-100 text-purple-800',
            };
            return classes[status] || 'bg-gray-100 text-gray-800';
        },

        getStatusLabel(status) {
            const labels = {
                pending: 'Pending',
                answering: 'Being Answered',
                answered: 'Answered',
                broadcast_site: 'Broadcast',
                broadcast_all: 'Broadcast',
            };
            return labels[status] || status;
        },

        startAutoRefresh() {
            this.refreshTimer = setInterval(() => {
                this.fetchClarifications();
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
