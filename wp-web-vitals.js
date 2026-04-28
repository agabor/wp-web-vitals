document.addEventListener("DOMContentLoaded", function () {
    if (window.performance) {
        let measurements = {
            ttfb: 0,
            fcp: 0,
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

        function logWebVitals() {
            let userType = 'guest';
            if (document.body.classList.contains('logged-in')) {
                userType = 'logged_in';
            }

            fetch(wpWebVitals.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    ...measurements,
                    action: 'log_webvitals',
                    userType: userType,
                    url: window.location.href,
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
        }

        const observer = new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (entry.name === 'first-contentful-paint') {
                    measurements.fcp = entry.startTime;
                    setMeasurementTime();
                    console.log('FCP:', entry.startTime);
                    logWebVitals();
                }
            }
        });

        observer.observe({ type: 'paint', buffered: true });
    }
});