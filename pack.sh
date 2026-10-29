#!/bin/bash

rm -f wp-web-vitals.zip

cd ..
zip wp-web-vitals.zip wp-web-vitals/wp-web-vitals.php wp-web-vitals/wp-web-vitals.js wp-web-vitals/admin/web-vitals-admin.css wp-web-vitals/admin/web-vitals-admin.js wp-web-vitals/LICENSE wp-web-vitals/README.txt
mv wp-web-vitals.zip wp-web-vitals
cd -