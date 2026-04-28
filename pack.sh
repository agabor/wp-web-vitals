#!/bin/bash

rm wp-web-vitals.zip

cd ..
zip wp-web-vitals.zip wp-web-vitals/wp-web-vitals.php wp-web-vitals/wp-web-vitals.js wp-web-vitals/performance.svg wp-web-vitals/LICENSE wp-web-vitals/README.txt
mv wp-web-vitals.zip wp-web-vitals
cd -