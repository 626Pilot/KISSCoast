# !!! WARNING !!!
This is alpha-quality software. I've been testing it for awhile, and I THINK it should work on any file you give it, but I can't guarantee that. It would be a good idea for you to sit near your printer while it's printing.


# KISSCoast
KISSlicer post-processor that adds coast functionality, with separate coast lengths for prime pillars and regular paths. Multiprocessing was recently added, reducing the time to process a ~15MB G-code file (537,306 lines) from about six minutes to slightly less than five seconds on my machine.

This script requires PHP command-line support. You don't need to have a web server installed, as we are only using the PHP engine to run the script. This post-processor is developed on Linux and has not been tested on Windows or Mac OS X, although it should still be able to run unmodified.

WARNING: I haven't tested these commands on Windows or Mac. What it says below is my best guess. If you figure out how to do it on your OS, and the instructions differ from what I have below, please let me know by opening an issue here: https://github.com/626Pilot/KISSCoast/issues



# Prerequisites
Linux:
- Debian and derivatives (like Ubuntu): sudo apt-get install php-cli
- Other distros: Check your package manager for php-cli, or failing that, php.

Windows:
- php.net has instructions: http://php.net/manual/en/install.windows.commandline.php

Mac:
- PHP has been bundled with Mac OS X since version 10.0.0 (i.e., forever).
- php.net has more info: http://php.net/manual/en/install.macosx.bundled.php

If using Linux or Mac, you can do this to make the script execute by itself:
`chmod a+x KISSCoast.php`



# Usage
In KISSlicer, go to Printer -> Firmware and enter the correct line for your OS. Replace x.xx and y.yy with the values you want.

Linux / Mac:<br>
`"/path/to/KISSCoast.php" --coast=x.xx --primePillarCoast=y.yy --file="<FILE>" --overwrite --processes=32`

Windows:<br>
`"C:\path\to\PHP\php" -q "C:\path\to\KISSCoast.php" --coast=x.xx --primePillarCoast=y.yy --file="<FILE>" --overwrite --processes=32`

On my 2012-era computer with four hyperthreaded cores (8 virtual), I got very good results (under 5 seconds) with `--processes=32`. You might be able to get it running faster with some different number. 32 seems to be a good starting point.

You can also run the script directly from a shell or command prompt. Just replace <FILE> with the path to a G-code file you want to test with. You can use `test.gcode` (included) if you like. If you want to see the script spew a bunch of debug info, put --verbose at the end of the command line. If you don't want it to overwrite your input g-code, so that (for example) you can compare the input and output, don't use --overwrite and it will send the output to (filename).gcode_out.
