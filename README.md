# KISSCoast
KISSlicer post-processor that adds coast functionality, with separate coast lengths for prime pillars and regular paths.

This script requires PHP command-line support. You don't need to have a web server installed, as we are only using the PHP engine to run the script. This post-processor is developed on Linux and has not been tested on Windows or Mac OS X, although it should still be able to run unmodified.

WARNING: I haven't tested these commands on Windows or Mac. What it says below is my best guess. If you figure out how to do it on your OS, and the instructions differ from what I have below, please let me know by opening an issue here: https://github.com/626Pilot/KISSCoast/issues

Please note that I don't have a Windows or Mac computer to test this on, so I can't help you directly on those platforms.


# Prerequisites
Linux -
Debian and derivatives (like Ubuntu): sudo apt-get install php-cli
Other distros: Check your package manager for php-cli, and failing that, php.

Windows -
php.net has instructions: http://php.net/manual/en/install.windows.commandline.php

Mac -
PHP has been bundled with Mac OS X since version 10.0.0 (i.e., forever).
php.net has more info: http://php.net/manual/en/install.macosx.bundled.php

If using Linux or Mac, you can do this to make the script execute by itself:
chmod a+x KISSCoast.php



# Usage
In KISSlicer, go to Printer -> Firmware and enter the correct line for your OS. Replace x.xx and y.yy with the values you want.

Linux / Mac:
KISSCoast.php --coast=x.xx --primePillarCoast=y.yy --file="<FILE>"

Windows:
"C:\path\to\PHP\php" -q "C:\path\to\KISSCoast.php" --coast=x.xx --primePillarCoast=y.yy --file="<FILE>"

You can also run the script directly from a shell or command prompt. Just replace <FILE> with the path to a G-code file you want to test with. You can use test.gcode (included) if you like.
