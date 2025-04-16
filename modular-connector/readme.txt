=== Modular DS: Manage all your websites from a single dashboard ===
Contributors: modulards, uniqoders, davidgomezgam
Tags: backup, maintenance, Manage Multiple Sites, monitoring, update, security
Requires at least: 5.6
Tested up to: 6.8
Stable tag: 1.15.2
Requires PHP: 7.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html

Connect and manage all your WordPress websites in an easier and more efficient way. Backups, bulk updates, Uptime Monitor, vulnerabilities, client reports and much more.

== Description ==

Modular DS is the tool you need to improve your web maintenance processes and get your clients to value your work. In order to save time on day-to-day tasks while getting more recurring maintenance contracts.

With [Modular DS](https://modulards.com/en/) you will have a centralized panel from which to control and access all your WordPress websites to perform tasks such as:

* __Managing plugins, themes and WordPress__ versions in bulk. Updating, activating, deactivating, deleting...
* Status monitoring (__Uptime__) and SSL.
* Cloud __backups__ (__RGPD__ compliant)
* __Health and vulnerability__ monitoring
* __Maintenance reports for clients__ with statistics from Google Analytics, Search Console, PageSpeed, WooCommerce and any task performed on the website.

And what's more, you'll find a committed team on the other side that you can talk to whenever you need to. Because even though Modular is a software, there are people behind it.

[Try it now](https://app.modulards.com/register) and connect up to 5 websites with our free plan and see the time and headaches it saves you.

### All your websites in one place

Connect all your websites to Modular and organize them by teams or assign different tags to them so that you can easily control them from a single panel.

### Manage plugins, themes, WordPress versions...

On all your websites at once or one at a time. Control and update plugins, themes and WordPress versions without having to log in to the admin. And also, receive alerts when one of the versions you have installed has a known vulnerability.

### Backups

Leave your worries behind. Rest assured with an automatic backup system so that, no matter what happens, your client's website is protected. Recover your backup in just a few minutes by selecting the files you want to restore. RGPD compliant.

### Client reports

Automatically generate and send maintenance reports for your clients. With all the information about updates, statistics (Analytics and Search Console), performance, backups and even adding custom tasks you do for them. With dedicated hours, dates and screenshots.

### Uptime Monitor

Receive email or SMS alerts when your website is down. Because you can't always be aware of what's happening on all your sites and the last thing you want is an angry client telling you that their website is down.

Select the frequency of the checks, expected response time, words to search and even the time it takes to notify you. One of the best and most complete Uptime Monitor plugins for WordPress.

### Great support

We are the first tool on the market with support in English and Spanish. Because you deserve someone who understands you without complications.

== Installation ==

1. Create an account on [app.modulards.com/register](https://app.modulards.com/register/)
2. Follow the instructions to connect your first website
3. Start saving time by automating tasks

And if you have any problems, contact us and we will be happy to help you through the process.

= Minimum requirements: =

* WordPress 5.6 or higher
* PHP 7.4 or higher

== Frequently Asked Questions ==

= Is website maintenance important? =

Yes, a lot. And even more so with WordPress. And we're not just talking about updates (necessary for security and performance). If you are a professional and offer this service to your clients, you must make sure you have everything under control. For that, you need backups, an uptime monitor service, to be aware of vulnerabilities that may arise, etc...

= Is Modular DS free? =

Modular DS has a free plan with which you can connect up to 5 websites and test all the features in its basic version. With the premium plans, starting at 29€ per month, you will be able to connect unlimited websites and have access to all the features.

= Do I have to have my websites hosted on a specific hosting company? =

No, Modular DS works independently of your hosting service.

= Is Modular DS secure? =

Yes, we use the oAuth2 protocol for authentication of the connection between your website and our servers. Thanks to this protocol we generate tokens with expiration and revocable at any time. In addition, we do not store any WordPress user passwords.

= How does Modular DS stand out from other existing solutions? =

We have put a lot of emphasis on making the user experience of the application excellent. So you can manage all your websites in the simplest and most intuitive way possible. Something our clients love (you can see the plugin reviews). We are also constantly updating the tool and releasing new features.

= What does the plugin Modular Connector do? =

Modular Connector bridges the gap between your WordPress and Modular DS. Something fundamental for both systems to communicate easily, allowing commands such as plugin updates or backup scheduling to go back and forth.


== Screenshots ==

1. Modular DS dashboard
2. Website overview
3. Uptime Monitor
4. Integrations with GA4, Search Console and PageSpeed
5. Cloud backups
6. Google Analytics integration
7. Client reports builder
8. Client report details
9. Client report overview page

== Changelog ==
= v1.15.2 =
Release date: 2025-04-16

* New tab to reset the plugin settings
* FIXED: Error getting WordPress user list for 1-click login
* FIXED: Don't use persistent connection to database to avoid issues with some hosts
* FIXED: Minor bugs

= v1.15.1 =
Release date: 2025-04-14

* FIXED: Database connection error when WordPress can't provide the collation or charset
* FIXED: Method to validate database connection
* FIXED: Method to check if core upgrade was successful
* FIXED: Conflict with different plugins
* FIXED: Minor bugs

= v1.15.0 =
Release date: 2025-04-09

* Allow to connect multisite installations
* WordPress 1-click login user management
* Elementor and WooCommerce automatic database update
* New tab to download logs and clear own caches

= v1.14.2 =
Release date: 2025-04-04

* FIXED: Conflict when the object cache is configured but isn't really configured.

= v1.14.1 =
Release date: 2025-04-03

* New queuing system to improve async jobs management
* New cache system to avoid conflicts with object cache
* Improved PHP 8.4 compatibility
* Improved backup creation on slow servers
* FIXED: Conflict with different plugins
* FIXED: Backup tables exclusion error
* FIXED: Backup files exclusion error
* FIXED: Generation of health tests

= v1.12.3 =
Release date: 2025-03-05

* FIXED: Conflict with different plugins

= v1.12.2 =
Release date: 2025-03-05

* FIXED: Conflict with different plugins

= v1.12.1 =
Release date: 2025-03-04

* FIXED: Cleaning up orphaned backup files
* FIXED: Conflict with WooCommerce

= v1.12.0 =
Release date: 2025-03-02

* Compatibility to manage premium plugins has been improved.
* The manifest backup read has been optimized.
* FIXED: Error in backup system exclusion files
* FIXED: Error in the backup manifest file
* FIXED: Error in maintenance mode detection

= v1.11.2 =
Release date: 2025-02-18

* Use nonce as fallback to validate requests

= v1.11.1 =
Release date: 2025-02-18

* Validate nonce to maintain backward compatibility with older versions

= v1.11.0 =
Release date: 2025-02-18

* New JWT validation for loopback requests
* FIXED: Compatibility with some plugins
* FIXED: Errors in the WordPress manager (updater)

= v1.10.4 =
Release date: 2025-02-03

* FIXED: Error including non-existent function in health report
* FIXED: Error in our cache
* FIXED: Error when excluding folders from backups
* FIXED: Compatibility with new hosts
* FIXED: How to set white label name in health report
* FIXED: How to calculate files to include in backups

= v1.10.2 =
Release date: 2025-01-21

* FIXED: Reopen zip files in incremental backups
* FIXED: Minor bug in connection check

= v1.10.1 =
Release date: 2025-01-15

* Incremental backups bug fix

= v1.10.0 =
Release date: 2025-01-15

* Backup system improvements
* Incremental backups option
* White label bug fix

= v1.5.0 =
Release date: 2024-12-30

* New version of WordPress manager and backup system

= v1.3.0 =
Release date: 2024-11-30

* WooCommerce integration

= v1.2.0 =
Release date: 2024-09-26

* Manage your plugins and themes: install, activate, deactivate and delete plugins or themes from Modular in bulk.

= v1.0.11 =
Release date: 2024-06-03

* FIXED: Minor bug fixes

= v1.0.4 =
Release date: 2024-04-03

* FIXED: Use socket connection for database

= v1.0.2 =
Release date: 2024-03-11

* FIXED: Loading translations into the health report
* FIXED: Some translations

= v1.0.1 =
Release date: 2024-03-04

* Health and safety status of the site was improved

= v1.0.0 =
Release date: 2024-02-23

* Plugin white label. Personalize the Modular Connector plugin info.

= v0.60.0 =
Release date: 2024-01-06

* Health and safety status of the site

= v0.50.4 =
Release date: 2023-11-15

* New backup system

= v0.30.10 =
Release date: 2023-08-14

* FIXED: Use cron job as fallback
* FIXED: Replace POST with GET method in Ajax request

= v0.30.8 =
Release date: 2023-07-26

* FIXED: Removed non-ascii characters in name, description and author of site items

= v0.30.7 =
Release date: 2023-07-24

* The plugin/themes/core synchronization and update process is now performed asynchronously.
* A new error handler system has been introduced.
* The update and synchronize process has been optimized.
* A new event system has been created.

= v0.20.3 =
Release date: 2023-04-21

* Fixed: The blog URL was set as the redirect URI in the OAuth token confirmation.
* Fixed: Check if "shell" is available
* Fixed: Some database backups failed when the port was sent explicitly.

= v0.20.1 =
Release date: 2023-04-03

* English and Spanish translations are now loaded.
* Fixed: Check if file is readable before adding to backup

= v0.20.0 =
Release date: 2023-03-29

* Now allows backups to be created and uploaded asynchronously

= v0.10.2 =
Release date: 2023-03-08

* Fixed: Error when exporting database when 'mysqldump' is not available.

= v0.10.1 =
Release date: 2023-02-23

* Improved backup error processing.
* Dot files are not ignored now

= v0.10.0 =

Release date: 2023-02-17

* WordPress Registration
* Connect WordPress sites
* 1 click login to WordPress without username/password
* Connect Google Analytics
* Recurring and snapshot full backups

Access the [complete changelog](https://modulards.com/en/change-log/) in modulards.com.
