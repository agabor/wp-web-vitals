document.addEventListener("DOMContentLoaded", function () {
    if (window.performance) {
        let measurements = {
            ttfb: 0,
            fcp: 0,
            lcp: 0,
            inp: 0,
            cls: 0,
            measurementSeconds: 0
        };
        const startTime = performance.now();
        if (performance.getEntriesByType('navigation').length > 0) {
            const navigationEntry = performance.getEntriesByType('navigation')[0];
            measurements.ttfb = navigationEntry.responseStart - navigationEntry.requestStart;
            console.log('TTFB:', measurements.ttfb);
        }

        function setMeasurementTime() {
            measurements.measurementSeconds = (performance.now() - startTime) / 1000;
        }

        const observer = new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (entry.name === 'first-contentful-paint') {
                    measurements.fcp = entry.startTime;
                    setMeasurementTime();
                    console.log('FCP:', entry.startTime);
                } else if (entry.entryType === 'largest-contentful-paint') {
                    measurements.lcp = entry.startTime;
                    setMeasurementTime();
                    console.log('LCP:', entry.startTime);
                } else if (entry.entryType === 'event' && entry.name === 'interaction-to-next-paint') {
                    measurements.inp = entry.duration;
                    setMeasurementTime();
                    console.log('INP:', entry.startTime);
                } else if (entry.entryType === 'layout-shift' && !entry.hadRecentInput) {
                    measurements.cls += entry.value;
                    setMeasurementTime();
                    console.log('CLS:', measurements.cls);
                }
            }
        });

        observer.observe({ type: 'paint', buffered: true });
        observer.observe({ type: 'largest-contentful-paint', buffered: true });
        observer.observe({ type: 'event', buffered: true, durationThreshold: 0 });
        observer.observe({ type: 'layout-shift', buffered: true });

        var currentUrl = window.location.href;

        var userType = 'guest';
        if (typeof wp !== 'undefined' && typeof wp.userSettings !== 'undefined') {
            console.log(wp.userSettings);
            userType = 'logged_in';
        }

        setTimeout(() => {
            fetch(wpWebVitals.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    ...measurements,
                    action: 'log_webvitals',
                    userType: userType,
                    url: currentUrl,
                    nonce: wpWebVitals.nonce
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Successfully logged data:', data);
                    } else {
                        console.error('Error logging data:', data);
                    }
                })
                .catch(error => {
                    console.error('AJAX error:', error);
                });
        }, 5000);
    }
});
