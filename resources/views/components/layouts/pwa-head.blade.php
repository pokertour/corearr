<!-- Favicon -->
<link rel="icon" href="/assets/logo/logo.svg" type="image/svg+xml">
<link rel="shortcut icon" href="/assets/logo/logo.svg">
<link rel="manifest" href="/manifest.json">
<!-- PWA & Mobile Web App Meta -->
<meta name="theme-color" content="#0284C7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="CoreArr">
<link rel="apple-touch-icon" href="/assets/logo/logo.svg">

<!-- Service Worker Bootstrap -->
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').then(registration => {
                console.log('SW registered: ', registration);
            }).catch(registrationError => {
                console.log('SW registration failed: ', registrationError);
            });
        });
    }
</script>
