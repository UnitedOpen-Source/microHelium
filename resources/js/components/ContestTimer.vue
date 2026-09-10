<template>
    <div class="space-y-1">
        <span v-if="loading" class="text-sm text-muted-foreground" role="status">Carregando competição…</span>
        <template v-else-if="hasContest">
            <span class="block text-xs font-medium" :class="timerClass">{{ statusLabel }}</span>
            <span role="timer" :aria-label="statusLabel" class="block text-2xl font-mono font-semibold tracking-tight tabular-nums" :class="timerClass">{{ formattedTime }}</span>
            <span v-if="frozen && !ended && !upcoming" class="text-xs text-warning">Placar congelado</span>
        </template>
        <span v-else-if="failed" class="text-sm text-muted-foreground">Relógio indisponível <button type="button" @click="fetchContestData" class="text-primary underline">Tentar novamente</button></span>
        <span v-else class="text-sm text-muted-foreground">Sem competição ativa</span>
    </div>
</template>
<script>
export default {
    props: {
        startTime: { type: String, default: null },
        duration: { type: Number, default: 0 },
        freezeTime: { type: Number, default: 60 },
    },
    data() { return { now: Date.now(), timer: null, refresh: null, contestData: null, loading: true, failed: false, request: null }; },
    computed: {
        contestStartTime() { return this.contestData?.start_time ?? this.startTime; },
        contestDuration() { return Number(this.contestData?.duration ?? this.duration); },
        start() { return new Date(this.contestStartTime).getTime(); },
        hasContest() { return Boolean(this.contestStartTime) && Number.isFinite(this.start) && this.contestDuration > 0; },
        elapsed() { return Math.floor((this.now - this.start) / 1000); },
        upcoming() { return this.elapsed < 0; },
        ended() { return this.elapsed >= this.contestDuration * 60; },
        frozen() { return this.elapsed >= (this.contestDuration - Number(this.contestData?.freeze_time ?? this.freezeTime)) * 60; },
        remainingSeconds() { return this.upcoming ? -this.elapsed : Math.max(0, this.contestDuration * 60 - this.elapsed); },
        statusLabel() { return this.upcoming ? 'Começa em' : this.ended ? 'Competição encerrada' : 'Tempo restante'; },
        timerClass() { return this.ended || this.upcoming ? 'text-muted-foreground' : this.remainingSeconds <= 300 ? 'text-destructive' : this.remainingSeconds <= 900 ? 'text-warning' : 'text-primary'; },
        formattedTime() {
            const seconds = this.remainingSeconds;
            return [Math.floor(seconds / 3600), Math.floor(seconds % 3600 / 60), seconds % 60].map(n => String(n).padStart(2, '0')).join(':');
        },
    },
    mounted() {
        this.fetchContestData();
        this.timer = setInterval(() => { this.now = Date.now(); }, 1000);
        this.refresh = setInterval(this.fetchContestData, 60000);
    },
    beforeUnmount() { clearInterval(this.timer); clearInterval(this.refresh); this.request?.abort(); },
    methods: {
        async fetchContestData() {
            this.request?.abort();
            const request = new AbortController();
            this.request = request;
            const timeout = setTimeout(() => request.abort(), 10000);
            try {
                const response = await fetch('/api/contest/current', { signal: request.signal, headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Contest unavailable');
                const data = await response.json();
                this.contestData = data?.start_time ? data : null;
                this.failed = false;
            } catch (_) { this.failed = true; this.contestData = null; }
            finally { clearTimeout(timeout); this.loading = false; this.now = Date.now(); }
        },
    },
};
</script>
