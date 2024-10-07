document.addEventListener("DOMContentLoaded", function () {
    if (window.performance) {
        var ttfb = performance.timing.responseStart - performance.timing.requestStart;
        var currentUrl = window.location.href;

        // Get user type from WordPress
        var userType = 'guest';
        if (typeof wp !== 'undefined' && typeof wp.userSettings !== 'undefined') {
            console.log(wp.userSettings);
            userType = 'logged_in';
        }


        // Send TTFB and URL to the server via AJAX
        fetch(ttfbLogger.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'log_ttfb',
                ttfb: ttfb,
                userType: userType,
                url: currentUrl,
                nonce: ttfbLogger.nonce
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('TTFB and URL logged successfully:', data);
            } else {
                console.error('Error logging data:', data);
            }
        })
        .catch(error => {
            console.error('AJAX error:', error);
        });
    }
});
