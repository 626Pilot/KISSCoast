# !!! WARNING !!!
This is alpha-quality software. It may:
- Not work
- Make your printer smash itself to pieces
- Cause things to catch on fire, or worse

While I don't think it's particularly dangerous, it is largely untested. Use at your own risk, and NEVER leave a print unattended. You need to be there to hit the power/kill switch in case anything goes wrong.


# KISSCoast
KISSlicer post-processor that adds coast functionality, with separate coast lengths for prime pillars and regular paths.

This script requires PHP command-line support. You don't need to have a web server installed, as we are only using the PHP engine to run the script. This post-processor is developed on Linux and has not been tested on Windows or Mac OS X, although it should still be able to run unmodified.

WARNING: I haven't tested these commands on Windows or Mac. What it says below is my best guess. If you figure out how to do it on your OS, and the instructions differ from what I have below, please let me know by opening an issue here: https://github.com/626Pilot/KISSCoast/issues



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
"/path/to/KISSCoast.php" --coast=x.xx --primePillarCoast=y.yy --file="<FILE>" --overwrite
If there are any spaces in the path to KISSCoast.php, 

Windows:
"C:\path\to\PHP\php" -q "C:\path\to\KISSCoast.php" --coast=x.xx --primePillarCoast=y.yy --file="<FILE>" --overwrite

You can also run the script directly from a shell or command prompt. Just replace <FILE> with the path to a G-code file you want to test with. You can use test.gcode (included) if you like. If you want to see the script spew a bunch of debug info, put --verbose at the end of the command line. If you don't want it to overwrite your input g-code, so that (for example) you can compare the input and output, don't use --overwrite.
