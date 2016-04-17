; Coasting implemented by KISSCoast.php
; -------------------------------------------------------------------------------
;           Author: 626Pilot (find me on the SeeMeCNC forums)
;           Source: https://github.com/626Pilot/KISSCoast
;        Timestamp: 2016-04-14 02:49:35
;            coast: 4
; primePillarCoast: 9
; -------------------------------------------------------------------------------
;
; [mm] mode
G21
G90 ; Smoothie: Absolute units 
M82 ; Duet: Absolute units
; Home
G28
; Heated bed & hot end control
M140 S50
M109 S185
;
; *** Main G-code ***
;
; Reset extruder pos
G92 E0
; BEGIN_LAYER_OBJECT z=0.17
;
; *** Selecting and Warming Extruder 2 to 185 C ***
; Select new extruder
T1
; Warm it up (non-blocking)
M104 S185
; Warm it up (blocking)
;M109 S185
; Prime filament
G92 E0
G1 E1 F150
G92 E0
;
G92 E0
;
;
; 'Prime Pillar Path', 0.9 [feed mm/s], 20.0 [head mm/s]
G1 X10 Y10 Z0.17 E0 F9000 
G1 E2 F1200
G1 X15 Y0 E3
G1 X20 Y0 E4
G1 X25 Y0 E5
G1 X30 Y0 E6
G1 X35 Y0 E7
G1 X40 Y0 E8
G1 X41 Y0 E8.2
G1 X45 Y0
G1 X50 Y0
;
; 'Destring/Wipe/Jump Path', 0.0 [feed mm/s], 20.0 [head mm/s]
G1 E1.5506 F900
G1 X50 Y50 F1200
G1 X45 Y45
;
; 'Perimeter Path', 0.6 [feed mm/s], 20.0 [head mm/s]
G1 X0 Y0 E0 F9000
G1 E2.0 F1200
G1 X0 Y5 E1 F1200
G1 X5 Y5 E2 F1200
G1 X5 Y0 E3 F1200
G1 X4 Y0 E3.2 F1200
G1 X0 Y0 F1200
;
; 'Destring/Wipe/Jump Path', 0.0 [feed mm/s], 20.0 [head mm/s]
G1 E3 F900
G1 X5 Y0 F1200
G1 X5 Y5
G1 X0 Y5
G1 X0 Y0
