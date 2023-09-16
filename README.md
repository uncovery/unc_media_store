# unc_media_store
A wordpress plugin to sell media files (e.g obs recordings) from a nextcloud storage via Stripe

## requirements:
- a nextcloud share
- a stripe account
- a linux cronjob running the same machine as the files are located to create thumbnails (optional)

## problem scenario:
- You regularly add files to a nextcloud folder and want to sell them on wordpress
but you are not able to maintain a permanently updating list of files in a webstore 
environment such as woocommerce
- You need a system that allows you to just know what files are there and let people
browse them and buy them via an interface online.

## ideal case preparation:
- You make sure that your filenames do not contain spaces (e.g. OBS studio advanced setttings)
- You run a script through a cronjob that sorts the files into directories based on their date
- You run a script through a cronjob that creates thumbnails for your videos

## solution approach:
- There is a machine that regularly records videos via OBS. OBS is configured to 
store the files in a folder inside of a nextcloud share automatically.
- This plugin creates a user interface that can be shown to via the shortcode \[ums_show_interface\]
- the interface allows users to browse through the files by date
- only when a user clicks on the buy-button, the system creates a product on the stripe server and forwards
the user to a page on the stripe server to purchase the file
- once the user has completed the purchase, they are redirected back to the wordpress
page. At this point only, the system creates a nextcloud share and sends the share link to the customer
for download

## advantages
- there is no need to touch the files. Everything is automatic
- there is no need to maintain a product list
- only files that are purchased will be shared via nextcloud. There is not need to
pre-generate share links
- only files that are purchased will be created as products on stripe. There is no need 
to manually maintain a list.

## caveats
this system is right now assuming that
- you have a thumbnail generated for each file
- the filenames are the default OBS file format, but without spaces (%CCYY-%MM-%DD_%hh-%mm-%ss)
- at this point, the list of files is only generated when the admin interface is opened

## installation
- install the plugin
- configure the nextcloud login & stripe secret keys. You can use restricted keys. The permissions need to be WRITE for Proructs, Checkout Sessions, Prices and Payment Links.
- create a new page in wordpress and insert the \[ums_show_interface\] shortcode

## future plans
- a cronjob to update the file list
