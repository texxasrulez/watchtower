# Watchtower (Roundcube plugin)

[![Packagist Downloads](https://img.shields.io/packagist/dt/texxasrulez/watchtower?style=plastic&logo=packagist&logoColor=white&label=Downloads&labelColor=blue&color=gold)](https://packagist.org/packages/texxasrulez/watchtower)
[![Packagist Version](https://img.shields.io/packagist/v/texxasrulez/watchtower?style=plastic&logo=packagist&logoColor=white&label=Version&labelColor=blue&color=limegreen)](https://packagist.org/packages/texxasrulez/watchtower)
[![Github License](https://img.shields.io/github/license/texxasrulez/watchtower?style=plastic&logo=github&label=License&labelColor=blue&color=coral)](https://github.com/texxasrulez/watchtower/LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/texxasrulez/watchtower?style=plastic&logo=github&label=Stars&labelColor=blue&color=deepskyblue)](https://github.com/texxasrulez/watchtower/stargazers)
[![GitHub Issues](https://img.shields.io/github/issues/texxasrulez/watchtower?style=plastic&logo=github&label=Issues&labelColor=blue&color=aqua)](https://github.com/texxasrulez/watchtower/issues)
[![GitHub Contributors](https://img.shields.io/github/contributors/texxasrulez/watchtower?style=plastic&logo=github&logoColor=white&label=Contributors&labelColor=blue&color=orchid)](https://github.com/texxasrulez/watchtower/graphs/contributors)
[![GitHub Forks](https://img.shields.io/github/forks/texxasrulez/watchtower?style=plastic&logo=github&logoColor=white&label=Forks&labelColor=blue&color=darkorange)](https://github.com/texxasrulez/watchtower/forks)
[![Donate Paypal](https://img.shields.io/badge/Paypal-Money_Please!-blue.svg?style=plastic&labelColor=blue&color=forestgreen&logo=paypal)](https://www.paypal.me/texxasrulez)

**Watchtower** adds a Settings → Watchtower panel intended for session
monitoring and login activity visualization.

This baseline:
- Wires a new Settings action: **Watchtower**
- Reads the Roundcube `session` table and shows recent sessions
  (user, IP, host, last activity)
- Uses two separate images for the Settings icon (normal + active) in Larry
- Keeps layout simple so it works with Larry variants and Elastic

## Installation

1. Extract the `watchtower` directory into your Roundcube `plugins/` folder:
   ```
   plugins/watchtower/
   ```

2. Copy the config template and adjust if needed:
   ```bash
   cd plugins/watchtower
   cp config.inc.php.dist config.inc.php
   ```

3. Enable the plugin in your Roundcube `config/config.inc.php`:
   ```php
   $config['plugins'][] = 'watchtower';
   ```

## Skins & Icons

- Larry (and my 10 Colorized Larry Variants):
  - Two separate images for the Settings icon: normal and active.
  - Icons live in `skins/larry/images/` as SVGs you can replace.

- Elastic:
  - Neutral layout and CSS, no sprite assumptions.
  
- Classic (I am nostalgic):
  - Neutral layout and CSS, no sprite assumptions.

## Extending Watchtower

Next steps I want to add to this plugin:

- Define "active" vs "stale" session rules.
- Create your own `watchtower_sessions` table and log full login events
  including user-agent and geoinfo.
- Add filters and a "suspicious activity" tab.

Enjoy!

:moneybag: **Donations** :moneybag:

If you use this plugin and would like to show your appreciation by buying me a cup of coffee, I surely would appreciate it. A regular cup of Joe is sufficient, but a Starbucks Coffee would be better ... \
Zelle (Zelle is integrated within many major banks Mobile Apps by default) - Just send to texxasrulez at yahoo dot com \
No Zelle in your banks mobile app, no problem, just click [Paypal](https://paypal.me/texxasrulez?locale.x=en_US) and I can make a Starbucks run ...

I appreciate the interest in this plugin and hope all the best ...

**Screenshot**
-----------

![Alt text](/images/screenshot.png?raw=true "Watchtower Screenshot")
