<template>
    <div class="surface p-6">
        <h2 class="text-lg font-semibold mb-4">Submit Solution</h2>

        <form @submit.prevent="submit">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Problem</label>
                    <select v-model="form.problem_id" class="w-full rounded-lg border border-input bg-background text-foreground px-3 py-2" required>
                        <option value="">Select a problem</option>
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
                    <label class="block text-sm font-medium mb-2">Language</label>
                    <select v-model="form.language_id" class="w-full rounded-lg border border-input bg-background text-foreground px-3 py-2" required>
                        <option value="">Select a language</option>
                        <option
                            v-for="language in languages"
                            :key="language.id"
                            :value="language.id"
                        >
                            {{ language.name }} (.{{ language.extension }})
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-2">Source File</label>
                    <input
                        type="file"
                        @change="handleFileChange"
                        class="w-full rounded-lg border border-input bg-background text-foreground px-3 py-2"
                        accept=".c,.cpp,.cc,.java,.py,.kt"
                        required
                    />
                    <p class="text-xs text-muted-foreground mt-1">
                        Maximum file size: {{ maxFileSize }}KB
                    </p>
                </div>

                <div v-if="error" class="p-3 bg-destructive-soft border border-destructive/25 rounded-lg text-destructive text-sm">
                    {{ error }}
                </div>

                <div v-if="success" class="p-3 bg-success-soft border border-success/25 rounded-lg text-success text-sm">
                    {{ success }}
                </div>

                <button
                    type="submit"
                    class="button-primary w-full"
                    :disabled="submitting"
                >
                    {{ submitting ? 'Submitting...' : 'Submit' }}
                </button>
            </div>
        </form>
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
        languages: {
            type: Array,
            required: true,
        },
        maxFileSize: {
            type: Number,
            default: 100,
        },
    },

    emits: ['submitted'],

    data() {
        return {
            form: {
                problem_id: '',
                language_id: '',
                source_file: null,
            },
            submitting: false,
            error: null,
            success: null,
        };
    },

    methods: {
        handleFileChange(event) {
            const file = event.target.files[0];
            if (file) {
                if (file.size > this.maxFileSize * 1024) {
                    this.error = `File too large. Maximum size is ${this.maxFileSize}KB`;
                    event.target.value = '';
                    return;
                }
                this.form.source_file = file;
                this.error = null;
            }
        },

        async submit() {
            this.error = null;
            this.success = null;
            this.submitting = true;

            try {
                const formData = new FormData();
                formData.append('contest_id', this.contestId);
                formData.append('problem_id', this.form.problem_id);
                formData.append('language_id', this.form.language_id);
                formData.append('source_file', this.form.source_file);

                const response = await axios.post('/runs', formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data',
                    },
                });

                this.success = `Submission #${response.data.run_number} received successfully!`;
                this.resetForm();
                this.$emit('submitted', response.data);
            } catch (error) {
                this.error = error.response?.data?.error || error.response?.data?.message || 'Submission failed';
            } finally {
                this.submitting = false;
            }
        },

        resetForm() {
            this.form.problem_id = '';
            this.form.language_id = '';
            this.form.source_file = null;
        },
    },
};
</script>
