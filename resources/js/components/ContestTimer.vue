<template>
    <div class="flex items-center space-x-2">
        <div v-if="hasContest" class="text-2xl font-mono font-bold" :class="timerClass">
            {{ formattedTime }}
        </div>
        <div v-else class="text-sm text-muted-foreground">
            Sem competicao ativa
        </div>
        <div v-if="frozen && hasContest" class="text-xs text-yellow-600 font-medium uppercase animate-pulse">
            Frozen
        </div>
    </div>
</template>

<script>
export default {
    props: {
        startTime: {
            type: String,
            default: null,
        },
        duration: {
            type: Number,
            default: 0,
        },
        freezeTime: {
            type: Number,
            default: 60,
        },
    },

    data() {
        return {
            currentTime: 0,
            timer: null,
            contestData: null,
        };
    },

    computed: {
        hasContest() {
            return this.contestStartTime && this.contestDuration > 0;
        },

        contestStartTime() {
            return this.contestData?.start_time || this.startTime;
        },

        contestDuration() {
            return this.contestData?.duration || this.duration;
        },

        totalSeconds() {
            return this.contestDuration * 60;
        },

        remainingSeconds() {
            return Math.max(0, this.totalSeconds - this.currentTime);
        },

        frozen() {
            const freezeTime = this.contestData?.freeze_time || this.freezeTime;
            const freezeSeconds = (this.contestDuration - freezeTime) * 60;
            return this.currentTime >= freezeSeconds && this.currentTime < this.totalSeconds;
        },

        ended() {
            return this.currentTime >= this.totalSeconds;
        },

        timerClass() {
            if (this.ended) return 'text-muted-foreground';
            if (this.remainingSeconds <= 300) return 'text-red-600 animate-pulse';
            if (this.remainingSeconds <= 900) return 'text-yellow-600';
            return 'text-green-600';
        },

        formattedTime() {
            if (!this.hasContest) return '--:--:--';

            const hours = Math.floor(this.remainingSeconds / 3600);
            const minutes = Math.floor((this.remainingSeconds % 3600) / 60);
            const seconds = this.remainingSeconds % 60;

            return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        },
    },

    mounted() {
        this.fetchContestData();
    },

    beforeUnmount() {
        this.stopTimer();
    },

    methods: {
        async fetchContestData() {
            try {
                const response = await fetch('/api/contest/current');
                if (response.ok) {
                    const data = await response.json();
                    if (data && data.start_time) {
                        this.contestData = data;
                        this.updateCurrentTime();
                        this.startTimer();
                    }
                }
            } catch (error) {
                // If API fails, try using props
                if (this.startTime && this.duration > 0) {
                    this.updateCurrentTime();
                    this.startTimer();
                }
            }
        },

        updateCurrentTime() {
            if (!this.contestStartTime) return;

            const start = new Date(this.contestStartTime);
            if (isNaN(start.getTime())) return;

            const now = new Date();
            this.currentTime = Math.max(0, Math.floor((now - start) / 1000));
        },

        startTimer() {
            if (!this.hasContest) return;

            this.timer = setInterval(() => {
                this.updateCurrentTime();
            }, 1000);
        },

        stopTimer() {
            if (this.timer) {
                clearInterval(this.timer);
            }
        },
    },
};
</script>
