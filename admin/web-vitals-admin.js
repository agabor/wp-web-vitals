const SERIES = [
    { suffix: 'guest_no_query', label: 'Guest (no query)', userType: 'guest', alpha: 0.7 },
    { suffix: 'guest_with_query', label: 'Guest (with query)', userType: 'guest', alpha: 0.4 },
    { suffix: 'logged_in_no_query', label: 'Logged-in (no query)', userType: 'logged_in', alpha: 0.7 },
    { suffix: 'logged_in_with_query', label: 'Logged-in (with query)', userType: 'logged_in', alpha: 0.4 }
];

const METRICS = {
    fcp: {
        canvasId: 'fcp-chart',
        dataPrefix: 'fcp_',
        colors: { guest: '54, 162, 235', logged_in: '75, 192, 75' },
        axisTitle: 'Milliseconds',
        integerTicks: false
    },
    ttfb: {
        canvasId: 'ttfb-chart',
        dataPrefix: 'ttfb_',
        colors: { guest: '255, 159, 64', logged_in: '255, 99, 132' },
        axisTitle: 'Milliseconds',
        integerTicks: false
    },
    requestCount: {
        canvasId: 'request-count-chart',
        dataPrefix: 'ttfb_count_',
        colors: { guest: '155, 89, 182', logged_in: '52, 152, 219' },
        axisTitle: 'Request Count',
        integerTicks: true
    }
};

function parseChartData(rawJson) {
    return JSON.parse(rawJson);
}

function buildDatasets(chartData, metric) {
    const style = METRICS[metric];

    return SERIES.map((series) => {
        const color = style.colors[series.userType];

        return {
            label: series.label,
            data: chartData[style.dataPrefix + series.suffix],
            backgroundColor: `rgba(${color}, ${series.alpha})`,
            borderColor: `rgba(${color}, ${series.alpha})`,
            fill: false,
            borderWidth: 2
        };
    });
}

function buildChartOptions(metric) {
    const style = METRICS[metric];

    const yAxis = {
        beginAtZero: true,
        title: {
            display: true,
            text: style.axisTitle
        }
    };

    // Request counts are whole numbers, so fractional ticks would read as impossible values.
    if (style.integerTicks) {
        yAxis.ticks = { stepSize: 1 };
    }

    return {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: yAxis
        },
        plugins: {
            legend: {
                display: true,
                position: 'top'
            }
        }
    };
}

function buildPageUrl(currentHref, pagePath) {
    const url = new URL(currentHref);

    if (pagePath) {
        url.searchParams.set('page_path', pagePath);
    } else {
        url.searchParams.delete('page_path');
    }

    return url.toString();
}

function renderCharts(chartData) {
    Object.keys(METRICS).forEach((metric) => {
        const context = document.getElementById(METRICS[metric].canvasId).getContext('2d');

        new Chart(context, {
            type: 'line',
            data: {
                labels: chartData.dates,
                datasets: buildDatasets(chartData, metric)
            },
            options: buildChartOptions(metric)
        });
    });
}

function postAdminAction(ajaxUrl, formFields) {
    return fetch(ajaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams(formFields)
    }).then((response) => response.json());
}

function showNotice(message, isError) {
    const notice = document.createElement('div');
    const typeClass = isError ? 'notice-error' : 'notice-success';
    notice.className = `notice ${typeClass} is-dismissible`;

    const paragraph = document.createElement('p');
    paragraph.textContent = message;
    notice.appendChild(paragraph);

    document.querySelector('#wp-web-vitals-admin h1').after(notice);
}

function handleAdminResponse(response) {
    showNotice(response.data, !response.success);

    // Reloading refreshes the page list and charts so they match the saved settings or cleared data.
    if (response.success) {
        window.location.reload();
    }
}

function handleRequestFailure() {
    showNotice('The request failed. Please try again.', true);
}

document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('wp-web-vitals-admin');
    if (!root) {
        return;
    }

    const ajaxUrl = root.dataset.ajaxUrl;
    const settingsNonce = root.dataset.settingsNonce;
    const cleanNonce = root.dataset.cleanNonce;

    renderCharts(parseChartData(root.dataset.chartData));

    document.getElementById('save-settings-button').addEventListener('click', function () {
        const pathPrefix = document.getElementById('path-prefix-input').value.trim();

        postAdminAction(ajaxUrl, {
            action: 'save_webvitals_settings',
            path_prefix: pathPrefix,
            nonce: settingsNonce
        })
            .then(handleAdminResponse)
            .catch(handleRequestFailure);
    });

    document.getElementById('clean-data-button').addEventListener('click', function () {
        if (!confirm('Are you sure you want to delete all data? This action cannot be undone.')) {
            return;
        }

        postAdminAction(ajaxUrl, {
            action: 'clean_webvitals_data',
            nonce: cleanNonce
        })
            .then(handleAdminResponse)
            .catch(handleRequestFailure);
    });

    document.getElementById('page-select').addEventListener('change', function () {
        window.location.href = buildPageUrl(window.location.href, this.value);
    });
});