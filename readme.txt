=== Gravity Forms ShootQ add-on ===
Contributors: pussycatdev
Donate link: http://www.pussycatintimates.com/gravity-forms-shootq-add-on/donate/
Tags: Gravity Forms, ShootQ, photographers, studio management system, leads, integration, add-on, data collection
Requires at least: 3.1
Tested up to: 3.3
Stable tag: 1.1.0

Connects your Gravity Forms to your ShootQ account for collecting leads.

== Description ==
Photographers, your wait is over! The Gravity Forms ShootQ add-on utilizes the awesome capabilities of [Gravity Forms](http://beauti.us/GForms) to collect **any information you desire** and send it your [ShootQ](http://www.shootq.com) account as a new lead. It's easy to use, very flexible, and only attaches to the form or forms you need to use.

== Installation ==
1. First **make sure** you have [Gravity Forms](http://beauti.us/GForms) installed first.
1. Upload the extracted archive to `wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Open the plugin settings page Forms -> Settings -> ShootQ or the settings link on the Plugins page
4. Add your ShootQ API Key and Brand Abbreviation
5. Add a "feed" to map the ShootQ fields to a pre-existing form
4. Start generating leads!

== Frequently Asked Questions ==
= I have more than one brand set up in ShootQ. Will this let me use both? =
Sorry, but no. This is a one brand pony show. Most people who have multiple ShootQ brands will also have separate domains for each brand. There are no plans at this time to change the plugin to allow multiple brands per installation.

= Does this work on a multisite installation of WordPress? =
This plugin has *not* been tested on a multi-site installation, so I can't tell you. Let me know if you find out!

= I can't find the settings page so I can enter my API Key and Brand Abbreviation. Where is it? =
You have mostly likely either not installed [Gravity Forms](http://beauti.us/GForms), have installed Gravity Forms after the ShootQ add-on instead of before, or - which has happened, trust me - you have installed Contact Form 7 instead of Gravity Forms. If you've done it correctly, it will be an item in the Gravity Forms admin menu (Forms -> Settings in the WordPress admin menu, then click the ShootQ link under the heading), or you can find it on the Plugins page in front of the Deactivate and Edit links for the plugin.

= Where can I get more help on getting this thing started? =
Visit my [Usage & Installation Instructions](http://www.pussycatintimates.com/gravity-forms-shootq-add-on/) page for plenty of assistance on getting everything configured to start collecting leads for ShootQ.

== Screenshots ==
1. Create a Gravity Form to use and map the ShootQ fields with those in your form.
2. Your visitor submits the form and *voila!* Everything gets sent to ShootQ!

== Changelog ==
= 1.1.0 =
* Updated to include available wedding-oriented fields from the ShootQ API, including ceremony and reception locations and times. Please make sure you edit your form mapping to include these new fields after you add them to your form!
= 1.0.8 = 
* Changed parsing of extra fields to include them in the order they appear in the form. So far, ShootQ has not made any fixes on their end, so your Additional Information section of the remarks in ShootQ will still be jumbled.
= 1.0.6 =
* Updated form parser to use the Admin Label instead of the Field Label if available to help keep the information sent to ShootQ readable.
= 1.0.5 =
* Patched to fix a fatal error when creating a new feed.
= 1.0.4 =
* Compartmentalized initialization functions to prevent scripts from loading when they aren't needed. Thanks to Jared at ProPhoto Blogs Support for the tip! :D
= 1.0.3 =
* Added validation to ShootQ API Key to help troubleshoot errors. This *does not verify that your API Key is correct,* only that it is in the proper format.
* Added code to trim spaces from API Key and Brand Abbreviation to help prevent errors created when cutting-and-pasting.
* Corrected a few spelling errors. Oops!
* Other miniscule error fixes.
= 1.0.2 =
* Fixed uninstall error in plugin base path.
= 1.0 =
* Initial release!

== Upgrade Notice ==
= 1.1.0 =
* Updated to include available wedding-oriented fields from the ShootQ API, including ceremony and reception locations and times.
= 1.0.5 =
* Patched to fix a fatal error when creating a new feed.
= 1.0.4 =
* You may see an increase in page load speed, at least in the admin if not site wide.
= 1.0.2 =
* Upgrade to allow proper uninstall (not that you'll want to!)
= 1.0 =
* Initial release. No upgrade necessary.