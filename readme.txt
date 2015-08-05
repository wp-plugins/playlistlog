=== Playlist Log ===
Contributors: cadeyrn
Donate link:
Tags: last.fm, audioscrobbler, scrobble, playlist, audio, listening, music
Requires at least: 3.0
Tested up to: 4.2.2
Stable tag: 0.3
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

A last.fm archive importer and [Audioscrobbler Protocol 1.2](http://www.audioscrobbler.net/development/protocol/) reciver to store and receive all the played entries a client sends to.

== Description ==

The plugin has two parts:

1. An importer for last.fm to import all scrobble data from last.fm provided zip files.
2. A reciver that supports [Audioscrobbler Protocol 1.2](http://www.audioscrobbler.net/development/protocol/) to store and receive all the played entries a client sends to.

The entries are stored as 'PlaylistLog' Custom Post types with 'Artist' and 'Album' custom taxnomomies and the default status of all posts is private.

== Installation ==

1. Upload contents of `playlistlog.zip` to the `/wp-content/plugins/` directory
2. Activate the plugin through the `Plugins` menu in WordPress
3. Go to Users -> Your Profile and set your PlaylistLog password.
3. Set [your site name]/playlistlog as Audioscrobbler URL in your client with your username and the PlaylistLog password.

== Changelog ==

Version numbering logic:

* every A. indicates BIG changes.
* every .B version indicates new features.
* every ..C indicates bugfixes for A.B version.

= 0.3 =
*2015-07-24*

* last.fm importer

= 0.2 =
*2015-07-23*

* plugin renamed - turned out I was using a trademarked name.

= 0.1 =
*2015-07-22*

* first stable release
