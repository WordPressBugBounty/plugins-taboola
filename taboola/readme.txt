=== Taboola ===
Contributors: Taboola
Tags: content recommendations, taboola, discovery,ad networks, ads, advertising, affiliate, content ads, contextual ads, Contextual Advertising, engagement, income, monetization, monetize, pay per click, popular posts, posts, ppc, related, Related Content, related post thumbnails, related posts, relevant ads, revenue, similar posts, text ads, widgets,recommendations,thumbnails, traffic, widget
Requires at least: 5.2
Tested up to: 6.6.0
Stable tag: trunk
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.txt

Use the Taboola plugin to generate revenue from native ads and drive engagement with editorial content.

== Description ==
This plugin provides an easy way to integrate Taboola content into your WordPress pages.
Using Taboola’s mix of sponsored and editorial content, you can generate revenue and drive engagement.
(Requires an account with Taboola. For more detail, see the <a href="https://developers.taboola.com/web-integrations/docs/wordpress-plugin/" target='_blank'>Taboola Dev Center</a>.)

== Installation ==

= Requirements =
* PHP 5.x, 7.x or 8.x
* WordPress 5.x or 6.x

= Installation Process =
* Log into the WordPress admin for your site
* Go to 'Plugins' -> 'Add New'
* Search for Taboola. Once found click on 'Install Now'
* Select 'Activate Plugin' from the download page or go to 'Plugins' -> 'Installed Plugins' -> 'Activate Plugin'

= Your Taboola widgets =
* Find Taboola in the ‘Installed Plugins’ section
* Select ‘Settings’ - this will take you to the plugin dashboard.
* If you have not already done so, contact Taboola to obtain your specific settings.
* Fill in your specific settings, as provided by Taboola.
* Your below-article unit will appear directly below your article. Your mid-article unit will appear directly below your chosen paragraph number.
* Click ‘Apply Changes’.
* The Taboola units should now appear on your article pages. 

For more detail, see the <a href="https://developers.taboola.com/web-integrations/docs/wordpress-plugin/" target='_blank'>Taboola Dev Center</a>.

== Frequently Asked Questions ==
= How do I use Taboola's Plugin? =
Once you have a Taboola account, reach out to us to obtain your specific Taboola settings. You can contact us through Backstage or reach out to your Taboola representative.

= Where do the units appear on my pages? =
Taboola works best on article pages - this is where your users are most engaged. Our below-article units appear directly after your content. The plugin manages this for you automatically. For your mid-article widget, choose a paragraph number - the unit will display beneath your chosen location.

= Can I have additional features, such as video or sticky ads? =
Yes, once you have added Taboola to your pages, you can request any other Taboola solution. You can do this in your Backstage account, or by contacting your Taboola representative. These additional solutions are activated by Taboola - no work required from you!

= Can I customize the UI of the Taboola units? =
While we have created a UI that we know performs the best, you can make changes. Simply contact us in your Backstage account or reach out to your Taboola representative. These customizations are activated by Taboola - no work required from you!

= How can I report security bugs?

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team help validate, triage and handle any security vulnerabilities. [Report a security vulnerability.](https://patchstack.com/database/vdp/taboola)

== Screenshots ==
1. **Taboola's settings page**

== Changelog ==

= 2.2.2 =
* Added the web push integration functionality and enabled push notifications

= 2.2.1 =
* The webPush scripts have been added to all pages, including Home, Category, and Article pages.

= 2.2.0 =
* Placed the Service Worker in the root directory

= 2.1.0 =
* Resolved the error and warnings

= 2.0.0 =
* Added the SDK script on the blog pages

= 2.1.1 =
* Fixed a bug that sometimes caused the creation of the settings table to fail, for certain MySQL versions

= 2.1.0 =
* Enhanced UI for sidebar units
* Security fix (HTML injection vulnerability)
* Tested on WordPress 6.3.2

= 2.0.2 =
* Security fix (CSRF vulnerability)

= 2.0.1 =
* Minor UI improvements

= 2.0.0 =
* Enhanced UI and functionality
* Support for below-article, mid-article and homepage widgets
* Tested on Wordpress 6.1.1

= 1.0.14 =
* Additional logging

= 1.0.13 =
* Minor fixes and improvements

= 1.0.12 =
* Minor Fix for PHP 8.0.x

= 1.0.11 =
* Support for PHP 8.0.x
* Tested on Wordpress 6.0.2

= 1.0.10 =
* Support for PHP 7.4.x
* Tested on Wordpress 6.0.1

= 1.0.8 =
* Renamed internal files
* Tested on Wordpress 4.7.3
* Place widget outside of main content element by default (to allow "Read More")

= 1.0.7 =
* Removed excess logs

= 1.0.6 =
* Better impressions reporting

= 1.0.5 =
* Added support for PHP 7

= 1.0.4 =
* Removed explicit http indication. it will automatically use the protocol from the page

= 1.0.3 =
* Added the ability to manually control where on the page the widget should be located

= 1.0.2 =
* Expanding the integration use cases that the template supports

= 1.0.1 =
* Added ability to place additional widgets on a page

= 1.0 =
* Initial release
