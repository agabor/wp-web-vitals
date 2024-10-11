# WP Web Vitals

> [!WARNING]  
> This plugin is currently in active development and has not been extensively tested on multiple websites. Please use it with caution, and verify the data it provides before relying on it fully.

## Project Overview

This project aims to gather Core Web Vitals and other essential metrics for both guest and logged-in users separately. This data is vital for eCommerce and membership websites seeking to enhance the experience for logged-in users.

## Motivation

Current Google performance tools, such as PageSpeed Insights and Search Console, do not distinguish between guest and logged-in users, providing only an average of the results. However, this information is crucial, because optimizing for these two user groups requires distinctly different strategies.

## Data Protection

All data is securely stored in the WordPress server’s database where this plugin is installed. This is a standalone plugin, and no external services can access the information processed by it. No personally identifiable information (PII) is stored.

## License

This project is licensed under the [GPLv3](https://www.gnu.org/licenses/gpl-3.0.html).

## Metrics

### Core Web Vitals

#### Largest Contentful Paint (LCP)

**State: Testing**

Measures the loading performance of a page. It tracks the time it takes for the largest visible content element (such as an image or text block) to become visible within the viewport. A good LCP score is 2.5 seconds or less.

#### First Input Delay (FID)

**State: Planned**

Measures interactivity. It calculates the time from when a user first interacts with a page (like clicking a link or button) to the moment the browser can respond to that interaction. A good FID score is less than 100 milliseconds.

#### Cumulative Layout Shift (CLS)

**State: Testing**

Measures visual stability. It quantifies how much the layout shifts during the loading phase of a page. A good CLS score is 0.1 or less, indicating that users experience minimal unexpected layout shifts.

#### Interaction to Next Paint (INP)

**State: Planned**

A new metric (introduced in 2023) that evaluates the overall responsiveness of a webpage by measuring the time between user interactions and the next visual change.

#### Time to First Byte (TTFB)

**State: Testing**

This metric measures the time taken from the moment a user makes a request to the server until the first byte of data is received. A good TTFB score is under 200 milliseconds.

### Additional Metric

#### First Contentful Paint (FCP)

**State: Testing**

 Specifically tracks the time it takes for the first piece of content (like text or images) to appear on the screen, indicating how quickly users can start consuming content. A good FCP score is under 1 second.
