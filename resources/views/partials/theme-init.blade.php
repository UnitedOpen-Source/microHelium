<meta name="theme-color" content="#f3f6f6">
<script>
    try {
        const theme = localStorage.getItem('theme');
        document.documentElement.classList.toggle('dark', theme === 'dark' || (!theme && matchMedia('(prefers-color-scheme: dark)').matches));
    } catch (_) {
        document.documentElement.classList.toggle('dark', matchMedia('(prefers-color-scheme: dark)').matches);
    }
    document.querySelector('meta[name="theme-color"]').content = document.documentElement.classList.contains('dark') ? '#101a22' : '#f3f6f6';
</script>
