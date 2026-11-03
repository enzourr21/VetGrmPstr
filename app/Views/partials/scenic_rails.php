<?php
$defaultScene = 'hero';
if (str_starts_with($curPath ?? '/', '/services') || str_starts_with($curPath ?? '/', '/appointments') || str_starts_with($curPath ?? '/', '/pets') || str_starts_with($curPath ?? '/', '/staff/')) {
    $defaultScene = 'services';
} elseif (str_starts_with($curPath ?? '/', '/products') || str_starts_with($curPath ?? '/', '/shop') || str_starts_with($curPath ?? '/', '/cart') || str_starts_with($curPath ?? '/', '/checkout') || str_starts_with($curPath ?? '/', '/orders') || str_starts_with($curPath ?? '/', '/profile')) {
    $defaultScene = 'community';
} elseif (str_starts_with($curPath ?? '/', '/staffs') || str_starts_with($curPath ?? '/', '/reviews')) {
    $defaultScene = 'hero';
}
?>
<!-- Full-Height Living Woodland & Animated Pet World (2 Animals Per Page: 1 Left, 1 Right; Present on all pages except Sign In / Sign Out) -->
<aside class="scenic-rail scenic-rail-left" aria-hidden="true" data-scenic-rail="left">
  <div class="scenic-stage">

    <!-- SCENE 1 LEFT: Sunny the Golden Retriever (Breathing Chest, Occasional Open-Mouth Panting & Moving Tongue, Blinking Eyes) -->
    <div class="scenic-figure <?= $defaultScene === 'hero' ? 'is-active' : '' ?>" data-scene-target="hero" data-animal="sunny">
      <svg viewBox="0 0 280 640" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Upper Canopy: Swaying Woodland Branches, Twigs & Veined Leaves -->
        <g class="scenic-layer scenic-canopy" data-part="canopy">
          <g class="anim-branch-sway" data-part="branch" data-pivot="10 42">
            <path d="M-12 42 C58 52, 128 78, 198 124" stroke="#6b4f3a" stroke-width="9" stroke-linecap="round"/>
            <path d="M45 58 C82 40, 132 45, 174 62" stroke="#846249" stroke-width="5.5" stroke-linecap="round"/>
            <path d="M112 82 C138 98, 156 126, 168 152" stroke="#846249" stroke-width="4.5" stroke-linecap="round"/>
            <path d="M22 48 C48 78, 62 114, 58 148" stroke="#7c5d46" stroke-width="4" stroke-linecap="round"/>
            <ellipse cx="36" cy="34" rx="46" ry="26" fill="#4d9078" opacity="0.88"/>
            <ellipse cx="98" cy="42" rx="52" ry="28" fill="#64a68d" opacity="0.9"/>
            <ellipse cx="156" cy="64" rx="40" ry="22" fill="#82bda7" opacity="0.88"/>
            <g class="anim-leaf-flutter" data-part="leaves" data-pivot="168 108">
              <path d="M168 108 C192 96, 220 106, 228 126 C206 136, 180 128, 168 108 Z" fill="#52997f"/>
              <path d="M172 110 L218 122" stroke="#a8dbc8" stroke-width="1.6" stroke-linecap="round"/>
              <path d="M140 132 C156 150, 182 158, 196 144 C186 126, 160 122, 140 132 Z" fill="#74b49b"/>
              <path d="M145 134 L188 143" stroke="#d0efe3" stroke-width="1.5" stroke-linecap="round"/>
            </g>
            <path d="M44 128 C64 118, 88 128, 92 148 C70 156, 50 146, 44 128 Z" fill="#3b7a63"/>
            <path d="M48 131 L85 145" stroke="#8ec6b0" stroke-width="1.5" stroke-linecap="round"/>
            <!-- Hanging Eucalyptus Vine -->
            <g class="anim-vine-sway" data-part="vine" data-pivot="78 55">
              <path d="M78 55 Q86 115 72 172" stroke="#528c74" stroke-width="2.5" stroke-linecap="round" fill="none"/>
              <ellipse cx="68" cy="88" rx="12" ry="7" transform="rotate(-25 68 88)" fill="#8ec6b0"/>
              <ellipse cx="90" cy="104" rx="12" ry="7" transform="rotate(22 90 104)" fill="#74b49b"/>
              <ellipse cx="66" cy="126" rx="11" ry="6.5" transform="rotate(-20 66 126)" fill="#8ec6b0"/>
              <ellipse cx="86" cy="144" rx="11" ry="6.5" transform="rotate(25 86 144)" fill="#64a68d"/>
            </g>
            <circle class="anim-prop-bob" cx="188" cy="88" r="5" fill="#e07a5f"/>
            <circle class="anim-prop-bob" cx="198" cy="95" r="4" fill="#f4a261"/>
          </g>
        </g>

        <!-- Midground Character: Sunny the Breathing & Panting Golden Retriever -->
        <g class="scenic-layer scenic-character">
          <path d="M18 392 C42 350, 74 322, 108 308" stroke="#6b4f3a" stroke-width="6" stroke-linecap="round"/>
          <path d="M236 398 C212 362, 188 336, 162 320" stroke="#7c5d46" stroke-width="5" stroke-linecap="round"/>
          <circle cx="64" cy="390" r="46" fill="#4d9078"/>
          <circle cx="212" cy="388" r="44" fill="#3b7a63"/>
          <circle cx="138" cy="372" r="58" fill="#64a68d"/>
          <ellipse cx="138" cy="432" rx="112" ry="26" fill="#cde8de"/>

          <!-- Wagging Plume Tail (Pivots at 182, 356) -->
          <g class="anim-tail-sway" data-part="tail" data-pivot="182 356">
            <path d="M182 356 C224 338, 246 302, 230 278 C218 262, 198 286, 178 328" fill="#e58e49"/>
            <path d="M196 340 C222 324, 234 298, 224 284" stroke="#f7b277" stroke-width="5" stroke-linecap="round"/>
          </g>

          <!-- Breathing Chest & Ribcage Group (Pivots at 137, 396) -->
          <g class="anim-dog-breathe" data-part="chest" data-pivot="137 396">
            <rect x="88" y="294" width="98" height="118" rx="44" fill="#f4a261"/>
            <path d="M104 328 L94 336 L106 344 M170 328 L180 336 L168 344" stroke="#d97736" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M110 318 C110 296, 164 296, 164 318 C170 356, 158 392, 137 398 C116 392, 104 356, 110 318 Z" fill="#fde4cf"/>
            <path d="M126 334 Q137 344 148 334 M122 352 Q137 362 152 352" stroke="#f4bd94" stroke-width="2.2" stroke-linecap="round" fill="none"/>
            <path d="M100 302 Q137 318 174 302" stroke="#0d6e57" stroke-width="10" stroke-linecap="round" fill="none"/>
            <circle cx="137" cy="316" r="8" fill="#f6bd60" stroke="#d9822b" stroke-width="2"/>
            <circle cx="135" cy="314" r="2" fill="#fff8eb"/>
          </g>

          <!-- Articulated Head, Ears, Blinking Eyelids & Panting Mouth/Tongue (Pivots at 137, 290) -->
          <g class="anim-head-bob" data-part="head" data-pivot="137 290">
            <g class="anim-ear-perk-left" data-part="ear-l" data-pivot="96 212">
              <ellipse cx="82" cy="238" rx="22" ry="44" transform="rotate(-20 82 238)" fill="#d97736"/>
              <ellipse cx="84" cy="242" rx="13" ry="30" transform="rotate(-20 84 242)" fill="#c26325"/>
            </g>
            <g class="anim-ear-perk-right" data-part="ear-r" data-pivot="178 212">
              <ellipse cx="192" cy="238" rx="22" ry="44" transform="rotate(20 192 238)" fill="#d97736"/>
              <ellipse cx="190" cy="242" rx="13" ry="30" transform="rotate(20 190 242)" fill="#c26325"/>
            </g>
            <circle cx="137" cy="238" r="52" fill="#f4a261"/>
            <path d="M126 188 Q134 174 140 186 Q148 176 152 190" stroke="#f4a261" stroke-width="6" stroke-linecap="round" fill="none"/>
            <ellipse cx="116" cy="212" rx="9" ry="5" transform="rotate(-10 116 212)" fill="#fde4cf" opacity="0.85"/>
            <ellipse cx="158" cy="212" rx="9" ry="5" transform="rotate(10 158 212)" fill="#fde4cf" opacity="0.85"/>
            <ellipse cx="137" cy="256" rx="30" ry="23" fill="#fde4cf"/>

            <!-- Eyes + Fur Eyelids for Unmistakable Natural Blinking -->
            <g class="anim-eyes anim-eyes-1" data-part="eyes" data-pivot="137 228">
              <circle cx="116" cy="228" r="7.5" fill="#1d2a26"/>
              <circle cx="114" cy="225.5" r="2.6" fill="#ffffff"/>
              <circle cx="118.5" cy="230" r="1.2" fill="#ffffff"/>
              <circle cx="158" cy="228" r="7.5" fill="#1d2a26"/>
              <circle cx="156" cy="225.5" r="2.6" fill="#ffffff"/>
              <circle cx="160.5" cy="230" r="1.2" fill="#ffffff"/>
            </g>
            <g data-part="eyelids" opacity="0">
              <ellipse cx="116" cy="228" rx="9" ry="8.5" fill="#e58e49"/>
              <path d="M107 229 Q116 234 125 229" stroke="#1d2a26" stroke-width="2.5" stroke-linecap="round" fill="none"/>
              <ellipse cx="158" cy="228" rx="9" ry="8.5" fill="#e58e49"/>
              <path d="M149 229 Q158 234 167 229" stroke="#1d2a26" stroke-width="2.5" stroke-linecap="round" fill="none"/>
            </g>

            <!-- Nose & Upper Muzzle Lip -->
            <ellipse cx="137" cy="245" rx="11" ry="8" fill="#1d2a26"/>
            <ellipse cx="134" cy="242.5" rx="4" ry="2" fill="#52615c"/>
            <path d="M121 258 Q137 268 153 258" stroke="#1d2a26" stroke-width="3.2" stroke-linecap="round" fill="none"/>

            <!-- Articulated Lower Jaw & Panting Tongue (Opens & Pants or Barks Periodically) -->
            <g data-part="jaw" data-pivot="137 260">
              <path data-part="mouth-open" d="M124 259 Q137 279 150 259 Z" fill="#7f1d1d" stroke="#1d2a26" stroke-width="2"/>
              <g class="anim-tongue-pant" data-part="tongue" data-pivot="137 262">
                <path d="M128 261 Q137 291 146 261 Z" fill="#f27059"/>
                <line x1="137" y1="262" x2="137" y2="278" stroke="#c84b31" stroke-width="2" stroke-linecap="round"/>
              </g>
            </g>
          </g>

          <!-- Temporary Bark Dialogue Bubble ("Woof! ♪") — Appears ONLY during active barking -->
          <g class="scenic-dialogue anim-dialogue-bark" data-part="dialogue-bark" data-pivot="180 186" opacity="0" style="opacity:0">
            <path d="M178 214 Q186 220 180 228" stroke="#0d6e57" stroke-width="2.2" stroke-linecap="round" fill="none"/>
            <path d="M184 208 Q195 218 186 231" stroke="#10b981" stroke-width="2" stroke-linecap="round" fill="none"/>
            <rect x="172" y="148" width="88" height="36" rx="18" fill="#ffffff" stroke="#0d6e57" stroke-width="2"/>
            <polygon points="186,183 176,198 198,183" fill="#ffffff"/>
            <polyline points="186,183 176,198 198,183" stroke="#0d6e57" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <text x="216" y="171" text-anchor="middle" fill="#0d6e57" font-family="system-ui, sans-serif" font-size="14" font-weight="750">Woof! ♪</text>
          </g>

          <!-- Mossy Foreground Log & Resting Paws -->
          <rect x="44" y="402" width="188" height="32" rx="16" fill="#7c5d46" stroke="#5e4330" stroke-width="2"/>
          <path d="M64 414 H118 M148 420 H208" stroke="#5e4330" stroke-width="2.5" stroke-linecap="round"/>
          <path d="M52 404 Q76 394 102 404 Q84 414 52 404 Z" fill="#64a68d"/>
          <path d="M174 404 Q198 394 222 404 Q202 414 174 404 Z" fill="#74b49b"/>
          <ellipse cx="112" cy="404" rx="16" ry="11" fill="#fde4cf" stroke="#e59856" stroke-width="2"/>
          <line x1="107" y1="406" x2="107" y2="413" stroke="#d97736" stroke-width="2" stroke-linecap="round"/>
          <line x1="117" y1="406" x2="117" y2="413" stroke="#d97736" stroke-width="2" stroke-linecap="round"/>
          <ellipse cx="162" cy="404" rx="16" ry="11" fill="#fde4cf" stroke="#e59856" stroke-width="2"/>
          <line x1="157" y1="406" x2="157" y2="413" stroke="#d97736" stroke-width="2" stroke-linecap="round"/>
          <line x1="167" y1="406" x2="167" y2="413" stroke="#d97736" stroke-width="2" stroke-linecap="round"/>
        </g>

        <!-- Lower Undergrowth: Swaying Ferns, Sticks & Berries -->
        <g class="scenic-layer scenic-undergrowth" data-part="undergrowth">
          <path d="M-8 585 C54 548, 114 524, 178 518" stroke="#6b4f3a" stroke-width="7.5" stroke-linecap="round"/>
          <path d="M98 538 C132 512, 168 498, 212 492" stroke="#846249" stroke-width="4.5" stroke-linecap="round"/>
          <circle cx="52" cy="566" r="44" fill="#3b7a63"/>
          <circle cx="122" cy="558" r="50" fill="#52997f"/>
          <circle cx="196" cy="568" r="42" fill="#64a68d"/>
          <circle cx="88" cy="596" r="46" fill="#74b49b"/>
          <circle cx="162" cy="598" r="44" fill="#4d9078"/>
          <g class="anim-fern-sway" data-part="fern" data-pivot="42 526">
            <path d="M42 526 Q68 476 106 456" stroke="#2d6a4f" stroke-width="3" stroke-linecap="round" fill="none"/>
            <ellipse cx="56" cy="504" rx="13" ry="6" transform="rotate(-35 56 504)" fill="#52b788"/>
            <ellipse cx="74" cy="486" rx="13" ry="6" transform="rotate(-30 74 486)" fill="#52b788"/>
            <ellipse cx="92" cy="470" rx="12" ry="5.5" transform="rotate(-25 92 470)" fill="#74c69d"/>
          </g>
          <circle class="anim-prop-bob" cx="144" cy="508" r="5.5" fill="#e07a5f"/>
          <circle class="anim-prop-bob" cx="156" cy="516" r="4.5" fill="#f4a261"/>
          <circle class="anim-prop-bob" cx="64" cy="542" r="5" fill="#e07a5f"/>
        </g>
      </svg>
      <span class="scenic-caption">Sunny · Golden Retriever</span>
    </div>

    <!-- SCENE 2 LEFT: Bella the Groomed Poodle (Idle Breathing, Articulated Barking Jaw + Temporary "Woof! ♪" Bubble) -->
    <div class="scenic-figure <?= $defaultScene === 'services' ? 'is-active' : '' ?>" data-scene-target="services" data-animal="bella">
      <svg viewBox="0 0 280 640" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Upper Canopy: Flowering Camellia Branches & Floating Spa Bubbles -->
        <g class="scenic-layer scenic-canopy" data-part="canopy">
          <g class="anim-branch-sway" data-part="branch" data-pivot="10 52">
            <path d="M-10 52 C64 62, 136 86, 206 132" stroke="#6b4f3a" stroke-width="8.5" stroke-linecap="round"/>
            <path d="M68 68 C108 50, 152 54, 194 74" stroke="#846249" stroke-width="5" stroke-linecap="round"/>
            <ellipse cx="44" cy="40" rx="48" ry="26" fill="#52997f"/>
            <ellipse cx="114" cy="48" rx="50" ry="26" fill="#74b49b"/>
            <ellipse cx="176" cy="74" rx="38" ry="20" fill="#8ec6b0"/>
            <circle cx="152" cy="96" r="14" fill="#f7cad0"/>
            <circle cx="152" cy="96" r="8" fill="#e07a5f"/>
            <circle cx="152" cy="96" r="3.5" fill="#f6bd60"/>
            <g class="anim-leaf-flutter" data-part="leaves" data-pivot="174 114">
              <path d="M174 114 C198 104, 224 114, 230 134 C208 142, 184 134, 174 114 Z" fill="#4d9078"/>
              <path d="M178 117 L222 130" stroke="#b7e4c7" stroke-width="1.6" stroke-linecap="round"/>
            </g>
          </g>
          <g class="anim-bubble-float" data-part="bubbles" data-pivot="58 152">
            <circle cx="58" cy="152" r="15" fill="#e0f4ff" fill-opacity="0.75" stroke="#7cc6fe" stroke-width="2"/>
            <path d="M50 146 A8 8 0 0 1 60 140" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
            <circle cx="46" cy="204" r="9" fill="#e0f4ff" fill-opacity="0.75" stroke="#7cc6fe" stroke-width="1.6"/>
            <circle cx="222" cy="148" r="16" fill="#e0f4ff" fill-opacity="0.75" stroke="#7cc6fe" stroke-width="2"/>
            <path d="M213 141 A9 9 0 0 1 224 134" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round"/>
          </g>
        </g>

        <!-- Midground Character: Bella the Barking Poodle -->
        <g class="scenic-layer scenic-character">
          <path d="M24 396 C54 354, 88 332, 124 318" stroke="#6b4f3a" stroke-width="6" stroke-linecap="round"/>
          <path d="M234 402 C206 364, 176 340, 148 324" stroke="#7c5d46" stroke-width="5.5" stroke-linecap="round"/>
          <circle cx="68" cy="390" r="46" fill="#64a68d"/>
          <circle cx="208" cy="390" r="44" fill="#4d9078"/>
          <circle cx="138" cy="374" r="56" fill="#82bda7"/>
          <ellipse cx="138" cy="432" rx="110" ry="24" fill="#d8eee6"/>

          <!-- Poodle Pom-Pom Tail (Pivots at 178, 364) -->
          <g class="anim-tail-sway" data-part="tail" data-pivot="178 364">
            <path d="M178 364 Q212 342 222 314" stroke="#e5d4c0" stroke-width="8" stroke-linecap="round"/>
            <circle cx="228" cy="302" r="22" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2.5"/>
            <circle cx="220" cy="296" r="8" fill="#ffffff"/>
          </g>

          <!-- Sculpted Cloud Coat & Breathing Body (Pivots at 138, 392) -->
          <g class="anim-dog-breathe" data-part="chest" data-pivot="138 392">
            <circle cx="138" cy="356" r="48" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2.5"/>
            <circle cx="108" cy="336" r="26" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2"/>
            <circle cx="168" cy="336" r="26" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2"/>
            <circle cx="138" cy="322" r="32" fill="#fffcf8"/>
          </g>

          <!-- Articulated Barking Poodle Head, Ears & Opening/Closing Mouth (Pivots at 138, 290) -->
          <g class="anim-poodle-bark" data-part="head" data-pivot="138 290">
            <g data-part="ear-l" data-pivot="94 236">
              <circle cx="84" cy="252" r="26" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2.5"/>
              <circle cx="80" cy="272" r="20" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2"/>
            </g>
            <g data-part="ear-r" data-pivot="182 236">
              <circle cx="192" cy="252" r="26" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2.5"/>
              <circle cx="196" cy="272" r="20" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2"/>
            </g>
            <circle cx="138" cy="248" r="44" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2.5"/>
            <circle cx="122" cy="198" r="22" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2"/>
            <circle cx="154" cy="198" r="22" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2"/>
            <circle cx="138" cy="192" r="24" fill="#fffcf8" stroke="#e5d4c0" stroke-width="2"/>
            <path d="M118 198 L158 212 L158 196 L118 210 Z" fill="#e07a5f"/>
            <circle cx="138" cy="204" r="5.5" fill="#c85a3d"/>

            <!-- Rosy Cheeks & Blinking Eyes -->
            <ellipse cx="108" cy="256" rx="7" ry="4" fill="#f7cad0"/>
            <ellipse cx="168" cy="256" rx="7" ry="4" fill="#f7cad0"/>
            <g class="anim-eyes anim-eyes-2" data-part="eyes" data-pivot="138 244">
              <circle cx="118" cy="244" r="6.5" fill="#1d2a26"/>
              <circle cx="116" cy="242" r="2.2" fill="#ffffff"/>
              <path d="M111 239 L106 235 M115 237 L112 232" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <circle cx="158" cy="244" r="6.5" fill="#1d2a26"/>
              <circle cx="156" cy="242" r="2.2" fill="#ffffff"/>
              <path d="M165 239 L170 235 M161 237 L164 232" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
            </g>
            <g data-part="eyelids" opacity="0">
              <ellipse cx="118" cy="244" rx="8.5" ry="8" fill="#fff8f0"/>
              <path d="M110 245 Q118 250 126 245" stroke="#1d2a26" stroke-width="2.4" stroke-linecap="round" fill="none"/>
              <ellipse cx="158" cy="244" rx="8.5" ry="8" fill="#fff8f0"/>
              <path d="M150 245 Q158 250 166 245" stroke="#1d2a26" stroke-width="2.4" stroke-linecap="round" fill="none"/>
            </g>

            <ellipse cx="138" cy="255" rx="8" ry="6" fill="#1d2a26"/>
            <path data-part="mouth-closed" d="M128 265 Q138 272 148 265" stroke="#1d2a26" stroke-width="2.6" stroke-linecap="round" fill="none"/>
            <g class="anim-bark-mouth" data-part="mouth-open" data-pivot="138 264" opacity="0">
              <ellipse cx="138" cy="270" rx="10" ry="8.5" fill="#991b1b" stroke="#1d2a26" stroke-width="2.2"/>
              <ellipse cx="138" cy="273" rx="6.5" ry="4.5" fill="#f27059"/>
            </g>
          </g>

          <!-- Temporary Bark Dialogue Bubble ("Woof! ♪") — Appears ONLY during active barking -->
          <g class="scenic-dialogue anim-dialogue-bark" data-part="dialogue-bark" data-pivot="180 190" opacity="0" style="opacity:0">
            <path d="M178 218 Q186 224 180 232" stroke="#0d6e57" stroke-width="2.2" stroke-linecap="round" fill="none"/>
            <path d="M184 212 Q195 222 186 235" stroke="#10b981" stroke-width="2" stroke-linecap="round" fill="none"/>
            <rect x="172" y="152" width="88" height="36" rx="18" fill="#ffffff" stroke="#0d6e57" stroke-width="2"/>
            <polygon points="186,187 176,202 198,187" fill="#ffffff"/>
            <polyline points="186,187 176,202 198,187" stroke="#0d6e57" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <text x="216" y="175" text-anchor="middle" fill="#0d6e57" font-family="system-ui, sans-serif" font-size="14" font-weight="750">Woof! ♪</text>
          </g>

          <!-- Poodle Anklet Cuffs on Garden Branch -->
          <rect x="46" y="404" width="184" height="28" rx="14" fill="#7c5d46" stroke="#5e4330" stroke-width="2"/>
          <circle cx="114" cy="402" r="14" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2"/>
          <circle cx="162" cy="402" r="14" fill="#fff8f0" stroke="#e5d4c0" stroke-width="2"/>
        </g>

        <!-- Lower Undergrowth -->
        <g class="scenic-layer scenic-undergrowth" data-part="undergrowth">
          <path d="M-10 580 C52 544, 118 524, 186 516" stroke="#6b4f3a" stroke-width="7.5" stroke-linecap="round"/>
          <circle cx="56" cy="568" r="44" fill="#4d9078"/>
          <circle cx="128" cy="558" r="50" fill="#64a68d"/>
          <circle cx="200" cy="570" r="40" fill="#3b7a63"/>
          <circle cx="94" cy="598" r="46" fill="#82bda7"/>
          <circle cx="166" cy="598" r="44" fill="#52997f"/>
          <g class="anim-fern-sway" data-part="fern" data-pivot="52 522">
            <path d="M52 522 Q82 478 118 462" stroke="#2d6a4f" stroke-width="3" stroke-linecap="round" fill="none"/>
            <ellipse cx="70" cy="498" rx="13" ry="6" transform="rotate(-30 70 498)" fill="#74c69d"/>
            <ellipse cx="94" cy="480" rx="13" ry="6" transform="rotate(-25 94 480)" fill="#52b788"/>
          </g>
          <circle class="anim-prop-bob" cx="174" cy="508" r="11" fill="#f7cad0"/>
          <circle class="anim-prop-bob" cx="174" cy="508" r="5.5" fill="#e07a5f"/>
        </g>
      </svg>
      <span class="scenic-caption">Bella · Grooming Studio</span>
    </div>

    <!-- SCENE 3 LEFT: Coco the Brown Dachshund (Continuous Natural Hip-Pivoted Tail Wagging, Breathing & Blinking) -->
    <div class="scenic-figure <?= $defaultScene === 'community' ? 'is-active' : '' ?>" data-scene-target="community" data-animal="coco">
      <svg viewBox="0 0 280 640" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Upper Canopy -->
        <g class="scenic-layer scenic-canopy" data-part="canopy">
          <g class="anim-branch-sway" data-part="branch" data-pivot="10 46">
            <path d="M-12 46 C62 56, 134 82, 204 128" stroke="#6b4f3a" stroke-width="9" stroke-linecap="round"/>
            <path d="M54 62 C98 44, 146 48, 188 70" stroke="#846249" stroke-width="5" stroke-linecap="round"/>
            <ellipse cx="42" cy="38" rx="46" ry="25" fill="#3b7a63"/>
            <ellipse cx="108" cy="44" rx="52" ry="27" fill="#52997f"/>
            <ellipse cx="172" cy="68" rx="40" ry="22" fill="#74b49b"/>
            <g class="anim-leaf-flutter" data-part="leaves" data-pivot="156 106">
              <path d="M156 106 C182 94, 212 104, 220 124 C198 134, 170 126, 156 106 Z" fill="#4d9078"/>
              <path d="M160 109 L212 121" stroke="#b7e4c7" stroke-width="1.6" stroke-linecap="round"/>
            </g>
            <circle class="anim-prop-bob" cx="136" cy="96" r="5.5" fill="#e07a5f"/>
            <circle class="anim-prop-bob" cx="148" cy="102" r="4.5" fill="#f4a261"/>
            <circle class="anim-prop-bob" cx="140" cy="108" r="4.5" fill="#e76f51"/>
          </g>
        </g>

        <!-- Midground Character: Coco the Chestnut Brown Dachshund -->
        <g class="scenic-layer scenic-character">
          <path d="M20 394 C52 350, 88 326, 126 314" stroke="#6b4f3a" stroke-width="6" stroke-linecap="round"/>
          <path d="M238 398 C208 360, 178 336, 150 320" stroke="#7c5d46" stroke-width="5.5" stroke-linecap="round"/>
          <circle cx="64" cy="390" r="46" fill="#4d9078"/>
          <circle cx="212" cy="388" r="44" fill="#52997f"/>
          <circle cx="138" cy="374" r="56" fill="#64a68d"/>
          <ellipse cx="138" cy="432" rx="112" ry="24" fill="#cde8de"/>

          <!-- Energetic Wagging Brown Tail (Pivots at exact hip attachment 194, 342) -->
          <g class="anim-dachshund-tail" data-part="tail" data-pivot="194 342">
            <path d="M194 342 C222 314, 238 282, 226 262" stroke="#8d5524" stroke-width="13" stroke-linecap="round" fill="none"/>
            <path d="M194 342 C218 318, 230 290, 222 270" stroke="#a66a38" stroke-width="5" stroke-linecap="round" fill="none"/>
            <path d="M206 270 Q214 258 208 248" stroke="#c68642" stroke-width="2.5" stroke-linecap="round" opacity="0.8"/>
            <path d="M238 282 Q248 272 244 260" stroke="#c68642" stroke-width="2.5" stroke-linecap="round" opacity="0.8"/>
          </g>

          <!-- Dachshund Breathing Body (Pivots at 136, 388) -->
          <g class="anim-dog-breathe" data-part="chest" data-pivot="136 388">
            <rect x="64" y="318" width="144" height="72" rx="36" fill="#8d5524"/>
            <ellipse cx="112" cy="356" rx="34" ry="24" fill="#c68642"/>
            <path d="M74 322 Q104 342 126 320" stroke="#0d6e57" stroke-width="8" stroke-linecap="round" fill="none"/>
            <circle cx="106" cy="336" r="6" fill="#f6bd60"/>
          </g>

          <!-- Articulated Head, Velvet Ear & Blinking Eye (Pivots at 98, 304) -->
          <g class="anim-head-bob" data-part="head" data-pivot="98 304">
            <circle cx="98" cy="264" r="42" fill="#8d5524"/>
            <ellipse cx="134" cy="274" rx="28" ry="18" fill="#8d5524"/>
            <ellipse cx="138" cy="280" rx="22" ry="12" fill="#c68642"/>
            <g class="anim-ear-perk-left" data-part="ear-l" data-pivot="72 246">
              <ellipse cx="68" cy="278" rx="20" ry="38" transform="rotate(8 68 278)" fill="#5c3310"/>
            </g>
            <circle cx="110" cy="244" r="5" fill="#d99b66"/>
            <g class="anim-eyes anim-eyes-3" data-part="eyes" data-pivot="112 258">
              <circle cx="112" cy="258" r="7" fill="#1d2a26"/>
              <circle cx="110" cy="256" r="2.3" fill="#ffffff"/>
            </g>
            <g data-part="eyelids" opacity="0">
              <ellipse cx="112" cy="258" rx="8.5" ry="8" fill="#8d5524"/>
              <path d="M104 259 Q112 264 120 259" stroke="#1d2a26" stroke-width="2.4" stroke-linecap="round" fill="none"/>
            </g>
            <circle cx="156" cy="268" r="7.5" fill="#1d2a26"/>
            <circle cx="154" cy="266" r="2.2" fill="#52615c"/>
            <path d="M124 284 Q138 292 150 284" stroke="#1d2a26" stroke-width="2.5" stroke-linecap="round" fill="none"/>
          </g>

          <!-- Floating Warm Community Heart Emblem -->
          <g class="anim-heart-float" data-part="heart" data-pivot="186 212">
            <path d="M168 202 C168 190, 186 190, 186 202 C186 190, 204 190, 204 202 C204 218, 186 230, 186 230 C186 230, 168 218, 168 202 Z" fill="#e07a5f"/>
          </g>

          <!-- Grounded Woodland Log & Paws -->
          <rect x="44" y="402" width="188" height="30" rx="15" fill="#7c5d46" stroke="#5e4330" stroke-width="2"/>
          <ellipse cx="92" cy="400" rx="14" ry="9" fill="#c68642"/>
          <ellipse cx="176" cy="400" rx="14" ry="9" fill="#c68642"/>
        </g>

        <!-- Lower Undergrowth -->
        <g class="scenic-layer scenic-undergrowth" data-part="undergrowth">
          <path d="M-8 582 C54 546, 118 524, 184 516" stroke="#6b4f3a" stroke-width="7.5" stroke-linecap="round"/>
          <circle cx="54" cy="568" r="44" fill="#3b7a63"/>
          <circle cx="126" cy="558" r="50" fill="#52997f"/>
          <circle cx="198" cy="568" r="42" fill="#64a68d"/>
          <circle cx="90" cy="598" r="46" fill="#74b49b"/>
          <circle cx="164" cy="598" r="44" fill="#4d9078"/>
          <g class="anim-fern-sway" data-part="fern" data-pivot="48 524">
            <path d="M48 524 Q78 478 114 460" stroke="#2d6a4f" stroke-width="3" stroke-linecap="round" fill="none"/>
            <ellipse cx="66" cy="500" rx="13" ry="6" transform="rotate(-32 66 500)" fill="#52b788"/>
            <ellipse cx="90" cy="482" rx="13" ry="6" transform="rotate(-26 90 482)" fill="#74c69d"/>
          </g>
        </g>
      </svg>
      <span class="scenic-caption">Coco · Community Guest</span>
    </div>

  </div>
</aside>

<aside class="scenic-rail scenic-rail-right" aria-hidden="true" data-scenic-rail="right">
  <div class="scenic-stage">

    <!-- SCENE 1 RIGHT: Luna the Silver Tabby Cat (Meowing Mouth, Purring Body, Temporary "Meow~" & "Purr… zzz" Bubbles, Swishing Tail) -->
    <div class="scenic-figure <?= $defaultScene === 'hero' ? 'is-active' : '' ?>" data-scene-target="hero" data-animal="luna">
      <svg viewBox="0 0 280 640" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Upper Canopy -->
        <g class="scenic-layer scenic-canopy" data-part="canopy">
          <g class="anim-branch-sway-alt" data-part="branch" data-pivot="270 42">
            <path d="M292 42 C222 52, 152 78, 82 124" stroke="#6b4f3a" stroke-width="9" stroke-linecap="round"/>
            <path d="M235 58 C198 40, 148 45, 106 62" stroke="#846249" stroke-width="5.5" stroke-linecap="round"/>
            <path d="M168 82 C142 98, 124 126, 112 152" stroke="#846249" stroke-width="4.5" stroke-linecap="round"/>
            <ellipse cx="244" cy="34" rx="46" ry="26" fill="#4d9078" opacity="0.88"/>
            <ellipse cx="182" cy="42" rx="52" ry="28" fill="#64a68d" opacity="0.9"/>
            <ellipse cx="124" cy="64" rx="40" ry="22" fill="#82bda7" opacity="0.88"/>
            <g class="anim-leaf-flutter" data-part="leaves" data-pivot="112 108">
              <path d="M112 108 C88 96, 60 106, 52 126 C74 136, 100 128, 112 108 Z" fill="#52997f"/>
              <path d="M108 110 L62 122" stroke="#a8dbc8" stroke-width="1.6" stroke-linecap="round"/>
              <path d="M140 132 C124 150, 98 158, 84 144 C94 126, 120 122, 140 132 Z" fill="#74b49b"/>
              <path d="M135 134 L92 143" stroke="#d0efe3" stroke-width="1.5" stroke-linecap="round"/>
            </g>
            <g class="anim-vine-sway" data-part="vine" data-pivot="202 55">
              <path d="M202 55 Q194 115 208 172" stroke="#528c74" stroke-width="2.5" stroke-linecap="round" fill="none"/>
              <ellipse cx="212" cy="88" rx="12" ry="7" transform="rotate(25 212 88)" fill="#8ec6b0"/>
              <ellipse cx="190" cy="104" rx="12" ry="7" transform="rotate(-22 190 104)" fill="#74b49b"/>
              <ellipse cx="214" cy="126" rx="11" ry="6.5" transform="rotate(20 214 126)" fill="#8ec6b0"/>
            </g>
            <circle class="anim-prop-bob" cx="92" cy="88" r="5" fill="#f4a261"/>
            <circle class="anim-prop-bob" cx="82" cy="95" r="4" fill="#e07a5f"/>
          </g>
        </g>

        <!-- Midground Character: Luna the Silver Tabby Cat -->
        <g class="scenic-layer scenic-character">
          <path d="M262 392 C238 350, 206 322, 172 308" stroke="#6b4f3a" stroke-width="6" stroke-linecap="round"/>
          <path d="M44 398 C68 362, 92 336, 118 320" stroke="#7c5d46" stroke-width="5" stroke-linecap="round"/>
          <circle cx="216" cy="390" r="46" fill="#4d9078"/>
          <circle cx="68" cy="388" r="44" fill="#3b7a63"/>
          <circle cx="142" cy="372" r="58" fill="#64a68d"/>
          <ellipse cx="142" cy="432" rx="112" ry="26" fill="#cde8de"/>

          <!-- Swishing Ringed Tabby Tail (Pivots at 180, 378) -->
          <g class="anim-cat-tail" data-part="tail" data-pivot="180 378">
            <path d="M180 378 C228 364, 244 304, 214 276" stroke="#7f8c9f" stroke-width="16" stroke-linecap="round" fill="none"/>
            <path d="M214 342 L226 336 M224 316 L234 312 M220 292 L228 286" stroke="#4a5568" stroke-width="4" stroke-linecap="round"/>
          </g>

          <!-- Purring & Breathing Cat Torso (Pivots at 142, 396) -->
          <g class="anim-cat-purr" data-part="chest" data-pivot="142 396">
            <ellipse cx="142" cy="350" rx="46" ry="58" fill="#8d99ae"/>
            <path d="M104 334 Q118 338 124 332 M180 334 Q166 338 160 332 M100 354 Q116 358 122 352 M184 354 Q168 358 162 352" stroke="#5c677d" stroke-width="3.5" stroke-linecap="round" fill="none"/>
            <ellipse cx="142" cy="356" rx="26" ry="38" fill="#edf2f4"/>
          </g>

          <!-- Articulated Tabby Head, Ears, Blinking Emerald Eyes, Whiskers & Meowing Mouth (Pivots at 142, 286) -->
          <g class="anim-head-bob-alt" data-part="head" data-pivot="142 286">
            <g class="anim-ear-perk-left" data-part="ear-l" data-pivot="114 214">
              <polygon points="94,228 104,174 134,210" fill="#8d99ae"/>
              <polygon points="100,222 107,184 128,210" fill="#f7cad0"/>
            </g>
            <g class="anim-ear-perk-right" data-part="ear-r" data-pivot="170 214">
              <polygon points="190,228 180,174 150,210" fill="#8d99ae"/>
              <polygon points="184,222 177,184 156,210" fill="#f7cad0"/>
            </g>
            <circle cx="142" cy="244" r="46" fill="#8d99ae"/>
            <path d="M132 206 L136 220 M142 202 L142 218 M152 206 L148 220" stroke="#4a5568" stroke-width="3.2" stroke-linecap="round"/>

            <!-- Blinking Emerald Eyes + Eyelids -->
            <g class="anim-eyes anim-eyes-2" data-part="eyes" data-pivot="142 240">
              <circle cx="122" cy="240" r="8" fill="#a7f3d0"/>
              <ellipse cx="122" cy="240" rx="3.2" ry="6.5" fill="#1d2a26"/>
              <circle cx="119.5" cy="237" r="2.2" fill="#ffffff"/>
              <circle cx="162" cy="240" r="8" fill="#a7f3d0"/>
              <ellipse cx="162" cy="240" rx="3.2" ry="6.5" fill="#1d2a26"/>
              <circle cx="159.5" cy="237" r="2.2" fill="#ffffff"/>
            </g>
            <g data-part="eyelids" opacity="0">
              <ellipse cx="122" cy="240" rx="9.5" ry="9" fill="#8d99ae"/>
              <path d="M113 241 Q122 246 131 241" stroke="#1d2a26" stroke-width="2.4" stroke-linecap="round" fill="none"/>
              <ellipse cx="162" cy="240" rx="9.5" ry="9" fill="#8d99ae"/>
              <path d="M153 241 Q162 246 171 241" stroke="#1d2a26" stroke-width="2.4" stroke-linecap="round" fill="none"/>
            </g>

            <!-- Pink Nose & Articulated Meowing Mouth -->
            <polygon points="136,252 148,252 142,260" fill="#e07a5f"/>
            <path data-part="mouth-closed" d="M132 264 Q137 270 142 264 Q147 270 152 264" stroke="#1d2a26" stroke-width="2.4" stroke-linecap="round" fill="none"/>
            <g class="anim-meow-mouth" data-part="mouth-open" data-pivot="142 264" opacity="0">
              <ellipse cx="142" cy="269" rx="7.5" ry="6.5" fill="#991b1b" stroke="#1d2a26" stroke-width="2"/>
              <ellipse cx="142" cy="271" rx="5" ry="3.8" fill="#f7cad0"/>
            </g>

            <!-- Twitching Whiskers -->
            <g class="anim-whiskers" data-part="whiskers" data-pivot="142 258">
              <line x1="76" y1="248" x2="110" y2="253" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <line x1="74" y1="258" x2="110" y2="259" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <line x1="78" y1="268" x2="112" y2="265" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <line x1="208" y1="248" x2="174" y2="253" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <line x1="210" y1="258" x2="174" y2="259" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <line x1="206" y1="268" x2="172" y2="265" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
            </g>
          </g>

          <!-- Temporary Cat "Meow~" & "Purr… zzz" Dialogue Bubbles -->
          <g class="scenic-dialogue anim-dialogue-meow" data-part="dialogue-meow" data-pivot="90 190" opacity="0" style="opacity:0">
            <rect x="18" y="156" width="84" height="34" rx="17" fill="#ffffff" stroke="#0d6e57" stroke-width="2"/>
            <polygon points="82,189 96,204 70,189" fill="#ffffff"/>
            <polyline points="82,189 96,204 70,189" stroke="#0d6e57" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <text x="60" y="178" text-anchor="middle" fill="#0d6e57" font-family="system-ui, sans-serif" font-size="13.5" font-weight="750">Meow~</text>
          </g>
          <g class="scenic-dialogue anim-dialogue-zzz" data-part="dialogue-zzz" data-pivot="190 180" opacity="0" style="opacity:0">
            <rect x="176" y="148" width="86" height="32" rx="16" fill="#ffffff" stroke="#64a68d" stroke-width="1.8"/>
            <circle cx="182" cy="190" r="4" fill="#ffffff" stroke="#64a68d" stroke-width="1.5"/>
            <circle cx="174" cy="200" r="2.5" fill="#ffffff" stroke="#64a68d" stroke-width="1.5"/>
            <text x="219" y="169" text-anchor="middle" fill="#0d6e57" font-family="system-ui, sans-serif" font-size="12.5" font-weight="700">Purr… zzz</text>
          </g>

          <!-- Oak Branch & Velvet Paws -->
          <rect x="48" y="402" width="188" height="30" rx="15" fill="#7c5d46" stroke="#5e4330" stroke-width="2"/>
          <path d="M74 414 H134 M162 420 H214" stroke="#5e4330" stroke-width="2.5" stroke-linecap="round"/>
          <ellipse cx="122" cy="402" rx="14" ry="10" fill="#edf2f4" stroke="#8d99ae" stroke-width="2"/>
          <ellipse cx="162" cy="402" rx="14" ry="10" fill="#edf2f4" stroke="#8d99ae" stroke-width="2"/>
        </g>

        <!-- Lower Undergrowth -->
        <g class="scenic-layer scenic-undergrowth" data-part="undergrowth">
          <path d="M288 585 C226 548, 166 524, 102 518" stroke="#6b4f3a" stroke-width="7.5" stroke-linecap="round"/>
          <path d="M182 538 C148 512, 112 498, 68 492" stroke="#846249" stroke-width="4.5" stroke-linecap="round"/>
          <circle cx="228" cy="566" r="44" fill="#3b7a63"/>
          <circle cx="158" cy="558" r="50" fill="#52997f"/>
          <circle cx="84" cy="568" r="42" fill="#64a68d"/>
          <circle cx="192" cy="596" r="46" fill="#74b49b"/>
          <circle cx="118" cy="598" r="44" fill="#4d9078"/>
          <g class="anim-fern-sway-alt" data-part="fern" data-pivot="238 526">
            <path d="M238 526 Q212 476 174 456" stroke="#2d6a4f" stroke-width="3" stroke-linecap="round" fill="none"/>
            <ellipse cx="224" cy="504" rx="13" ry="6" transform="rotate(35 224 504)" fill="#52b788"/>
            <ellipse cx="206" cy="486" rx="13" ry="6" transform="rotate(30 206 486)" fill="#52b788"/>
            <ellipse cx="188" cy="470" rx="12" ry="5.5" transform="rotate(25 188 470)" fill="#74c69d"/>
          </g>
        </g>
      </svg>
      <span class="scenic-caption">Luna · Silver Tabby</span>
    </div>

    <!-- SCENE 2 RIGHT: Clover the Meadow Bunny (Independently Moving Ears, Twitching Nose, Breathing Body, NO + Sign) -->
    <div class="scenic-figure <?= $defaultScene === 'services' ? 'is-active' : '' ?>" data-scene-target="services" data-animal="clover">
      <svg viewBox="0 0 280 640" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Upper Canopy -->
        <g class="scenic-layer scenic-canopy" data-part="canopy">
          <g class="anim-branch-sway-alt" data-part="branch" data-pivot="270 50">
            <path d="M290 50 C218 60, 146 86, 76 132" stroke="#6b4f3a" stroke-width="8.5" stroke-linecap="round"/>
            <path d="M214 66 C172 48, 128 54, 86 74" stroke="#846249" stroke-width="5" stroke-linecap="round"/>
            <ellipse cx="236" cy="40" rx="48" ry="26" fill="#52997f"/>
            <ellipse cx="166" cy="48" rx="50" ry="26" fill="#74b49b"/>
            <ellipse cx="104" cy="74" rx="38" ry="20" fill="#8ec6b0"/>
            <g class="anim-leaf-flutter" data-part="leaves" data-pivot="106 114">
              <path d="M106 114 C82 104, 56 114, 50 134 C72 142, 96 134, 106 114 Z" fill="#4d9078"/>
              <path d="M102 117 L58 130" stroke="#b7e4c7" stroke-width="1.6" stroke-linecap="round"/>
            </g>
          </g>
        </g>

        <!-- Midground Character: Clover the Bunny (Living Independent Ears, Fluffy Chest Tuft, No + Sign) -->
        <g class="scenic-layer scenic-character">
          <path d="M256 396 C226 354, 192 332, 156 318" stroke="#6b4f3a" stroke-width="6" stroke-linecap="round"/>
          <path d="M46 402 C74 364, 104 340, 132 324" stroke="#7c5d46" stroke-width="5.5" stroke-linecap="round"/>
          <circle cx="212" cy="390" r="46" fill="#64a68d"/>
          <circle cx="72" cy="390" r="44" fill="#4d9078"/>
          <circle cx="142" cy="374" r="56" fill="#82bda7"/>
          <ellipse cx="142" cy="432" rx="110" ry="24" fill="#d8eee6"/>

          <!-- Fluffy Cottontail -->
          <circle cx="192" cy="372" r="18" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>

          <!-- Bunny Breathing Body with Soft Fluffy Chest Fur (No + Sign) -->
          <g class="anim-dog-breathe" data-part="chest" data-pivot="142 396">
            <ellipse cx="142" cy="348" rx="48" ry="56" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="2.5"/>
            <ellipse cx="142" cy="348" rx="28" ry="36" fill="#ffffff"/>
            <path d="M130 326 Q142 336 154 326 M126 344 Q142 356 158 344 M132 362 Q142 372 152 362" stroke="#cbd5e1" stroke-width="2.2" stroke-linecap="round" fill="none"/>
          </g>

          <!-- Bunny Head, Independently Articulated Living Ears, Blinking Eyes & Twitching Nose -->
          <g data-part="head" data-pivot="142 290">
            <g class="anim-bunny-ear-left" data-part="ear-l" data-pivot="118 226">
              <ellipse cx="114" cy="176" rx="16" ry="54" transform="rotate(-10 114 176)" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="2.5"/>
              <ellipse cx="114" cy="180" rx="8.5" ry="39" transform="rotate(-10 114 180)" fill="#f7cad0"/>
            </g>
            <g class="anim-bunny-ear-right" data-part="ear-r" data-pivot="166 226">
              <ellipse cx="170" cy="176" rx="16" ry="54" transform="rotate(10 170 176)" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="2.5"/>
              <ellipse cx="170" cy="180" rx="8.5" ry="39" transform="rotate(10 170 180)" fill="#f7cad0"/>
            </g>

            <circle cx="142" cy="256" r="42" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="2.5"/>
            <ellipse cx="112" cy="262" rx="7" ry="4" fill="#f7cad0" opacity="0.85"/>
            <ellipse cx="172" cy="262" rx="7" ry="4" fill="#f7cad0" opacity="0.85"/>

            <!-- Blinking Eyes + Eyelids -->
            <g class="anim-eyes anim-eyes-1" data-part="eyes" data-pivot="142 248">
              <circle cx="124" cy="248" r="6.5" fill="#1d2a26"/>
              <circle cx="122" cy="246" r="2.2" fill="#ffffff"/>
              <circle cx="160" cy="248" r="6.5" fill="#1d2a26"/>
              <circle cx="158" cy="246" r="2.2" fill="#ffffff"/>
            </g>
            <g data-part="eyelids" opacity="0">
              <ellipse cx="124" cy="248" rx="8" ry="7.5" fill="#f1f5f9"/>
              <path d="M116 249 Q124 254 132 249" stroke="#1d2a26" stroke-width="2.3" stroke-linecap="round" fill="none"/>
              <ellipse cx="160" cy="248" rx="8" ry="7.5" fill="#f1f5f9"/>
              <path d="M152 249 Q160 254 168 249" stroke="#1d2a26" stroke-width="2.3" stroke-linecap="round" fill="none"/>
            </g>

            <!-- Twitching Pink Nose & Whiskers -->
            <g class="anim-nose-twitch" data-part="nose" data-pivot="142 262">
              <ellipse cx="142" cy="260" rx="7.5" ry="5.5" fill="#e07a5f"/>
              <path d="M133 268 Q142 275 151 268" stroke="#1d2a26" stroke-width="2.2" stroke-linecap="round" fill="none"/>
              <line x1="94" y1="258" x2="122" y2="261" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round"/>
              <line x1="96" y1="266" x2="122" y2="265" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round"/>
              <line x1="190" y1="258" x2="162" y2="261" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round"/>
              <line x1="188" y1="266" x2="162" y2="265" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round"/>
            </g>
          </g>

          <!-- Woodland Log & Bunny Paws -->
          <rect x="48" y="404" width="184" height="28" rx="14" fill="#7c5d46" stroke="#5e4330" stroke-width="2"/>
          <ellipse cx="120" cy="402" rx="14" ry="9" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
          <ellipse cx="164" cy="402" rx="14" ry="9" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
        </g>

        <!-- Lower Undergrowth -->
        <g class="scenic-layer scenic-undergrowth" data-part="undergrowth">
          <path d="M290 580 C228 544, 162 524, 94 516" stroke="#6b4f3a" stroke-width="7.5" stroke-linecap="round"/>
          <circle cx="224" cy="568" r="44" fill="#4d9078"/>
          <circle cx="152" cy="558" r="50" fill="#64a68d"/>
          <circle cx="80" cy="570" r="40" fill="#3b7a63"/>
          <circle cx="186" cy="598" r="46" fill="#82bda7"/>
          <circle cx="114" cy="598" r="44" fill="#52997f"/>
          <g class="anim-fern-sway-alt" data-part="fern" data-pivot="228 522">
            <path d="M228 522 Q198 478 162 462" stroke="#2d6a4f" stroke-width="3" stroke-linecap="round" fill="none"/>
            <ellipse cx="210" cy="498" rx="13" ry="6" transform="rotate(30 210 498)" fill="#74c69d"/>
            <ellipse cx="186" cy="480" rx="13" ry="6" transform="rotate(25 186 480)" fill="#52b788"/>
          </g>
        </g>
      </svg>
      <span class="scenic-caption">Clover · Meadow Bunny</span>
    </div>

    <!-- SCENE 3 RIGHT: Pip the Woodland Songbird (Articulated Upper/Lower Chirping Beak, Wing Flutter, Temporary "Chirp! ♫" Bubble) -->
    <div class="scenic-figure <?= $defaultScene === 'community' ? 'is-active' : '' ?>" data-scene-target="community" data-animal="pip">
      <svg viewBox="0 0 280 640" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Upper Canopy -->
        <g class="scenic-layer scenic-canopy" data-part="canopy">
          <g class="anim-branch-sway-alt" data-part="branch" data-pivot="270 46">
            <path d="M292 46 C218 56, 146 82, 76 128" stroke="#6b4f3a" stroke-width="9" stroke-linecap="round"/>
            <path d="M226 62 C182 44, 134 48, 92 70" stroke="#846249" stroke-width="5" stroke-linecap="round"/>
            <ellipse cx="238" cy="38" rx="46" ry="25" fill="#3b7a63"/>
            <ellipse cx="172" cy="44" rx="52" ry="27" fill="#52997f"/>
            <ellipse cx="108" cy="68" rx="40" ry="22" fill="#74b49b"/>
            <g class="anim-leaf-flutter" data-part="leaves" data-pivot="124 106">
              <path d="M124 106 C98 94, 68 104, 60 124 C82 134, 110 126, 124 106 Z" fill="#4d9078"/>
              <path d="M120 109 L68 121" stroke="#b7e4c7" stroke-width="1.6" stroke-linecap="round"/>
            </g>
          </g>
        </g>

        <!-- Midground Character: Pip the Chirping Songbird -->
        <g class="scenic-layer scenic-character">
          <circle cx="212" cy="390" r="46" fill="#4d9078"/>
          <circle cx="72" cy="388" r="44" fill="#52997f"/>
          <circle cx="142" cy="374" r="56" fill="#64a68d"/>
          <ellipse cx="142" cy="432" rx="112" ry="24" fill="#cde8de"/>

          <!-- Articulated Bird Body, Wing, Head & Split Upper/Lower Beak (Pivots at 138, 352) -->
          <g class="anim-bird-hop" data-part="body" data-pivot="138 352">
            <g data-part="tail" data-pivot="160 348">
              <polygon points="164,344 218,392 194,404 148,354" fill="#2563eb"/>
            </g>
            <g data-part="chest" data-pivot="138 340">
              <ellipse cx="138" cy="308" rx="42" ry="48" fill="#4ea8de"/>
              <ellipse cx="128" cy="316" rx="26" ry="34" fill="#caf0f8"/>
            </g>
            <g class="anim-bird-wing" data-part="wing" data-pivot="148 284">
              <ellipse cx="152" cy="306" rx="22" ry="32" transform="rotate(-14 152 306)" fill="#2563eb"/>
              <line x1="138" y1="298" x2="166" y2="292" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
              <line x1="140" y1="312" x2="168" y2="306" stroke="#93c5fd" stroke-width="2.5" stroke-linecap="round"/>
            </g>
            <g data-part="head" data-pivot="132 266">
              <circle cx="132" cy="244" r="32" fill="#4ea8de"/>
              <polygon points="142,214 172,202 156,226" fill="#2563eb"/>

              <g class="anim-bird-beak" data-part="beak" data-pivot="105 241">
                <polygon data-part="beak-cavity" points="92,238 106,235 106,247 92,244" fill="#991b1b" opacity="0"/>
                <g data-part="beak-top" data-pivot="105 237">
                  <polygon points="84,238 106,231 105,241" fill="#f4a261" stroke="#d97736" stroke-width="1"/>
                </g>
                <g data-part="beak-bottom" data-pivot="105 242">
                  <polygon points="86,243 105,241 106,250" fill="#e58e49" stroke="#c26325" stroke-width="1"/>
                </g>
              </g>

              <g class="anim-eyes anim-eyes-3" data-part="eyes" data-pivot="122 238">
                <circle cx="122" cy="238" r="6" fill="#1d2a26"/>
                <circle cx="120.5" cy="236" r="2" fill="#ffffff"/>
              </g>
              <g data-part="eyelids" opacity="0">
                <ellipse cx="122" cy="238" rx="7.5" ry="7" fill="#4ea8de"/>
                <path d="M115 239 Q122 243 129 239" stroke="#1d2a26" stroke-width="2.2" stroke-linecap="round" fill="none"/>
              </g>
            </g>
          </g>

          <!-- Temporary Bird "Chirp! ♫" Dialogue Bubble -->
          <g class="scenic-dialogue anim-dialogue-chirp" data-part="dialogue-chirp" data-pivot="88 198" opacity="0" style="opacity:0">
            <rect x="16" y="162" width="88" height="34" rx="17" fill="#ffffff" stroke="#2563eb" stroke-width="2"/>
            <polygon points="82,195 94,212 68,195" fill="#ffffff"/>
            <polyline points="82,195 94,212 68,195" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <text x="60" y="184" text-anchor="middle" fill="#1e40af" font-family="system-ui, sans-serif" font-size="13.5" font-weight="750">Chirp! ♫</text>
          </g>

          <!-- Textured Woodland Perch Branch & Leaves -->
          <path d="M28 368 Q140 348 256 376" stroke="#6b4f3a" stroke-width="14" stroke-linecap="round"/>
          <path d="M56 364 Q92 332 118 338" stroke="#846249" stroke-width="5" stroke-linecap="round" fill="none"/>
          <ellipse cx="74" cy="342" rx="16" ry="8" transform="rotate(-22 74 342)" fill="#74c69d"/>
          <ellipse cx="204" cy="354" rx="16" ry="8" transform="rotate(18 204 354)" fill="#52b788"/>
        </g>

        <!-- Lower Undergrowth -->
        <g class="scenic-layer scenic-undergrowth" data-part="undergrowth">
          <path d="M288 582 C226 546, 162 524, 96 516" stroke="#6b4f3a" stroke-width="7.5" stroke-linecap="round"/>
          <circle cx="226" cy="568" r="44" fill="#3b7a63"/>
          <circle cx="154" cy="558" r="50" fill="#52997f"/>
          <circle cx="82" cy="568" r="42" fill="#64a68d"/>
          <circle cx="190" cy="598" r="46" fill="#74b49b"/>
          <circle cx="116" cy="598" r="44" fill="#4d9078"/>
          <g class="anim-fern-sway-alt" data-part="fern" data-pivot="232 524">
            <path d="M232 524 Q202 478 166 460" stroke="#2d6a4f" stroke-width="3" stroke-linecap="round" fill="none"/>
            <ellipse cx="214" cy="500" rx="13" ry="6" transform="rotate(32 214 500)" fill="#52b788"/>
            <ellipse cx="190" cy="482" rx="13" ry="6" transform="rotate(26 190 482)" fill="#74c69d"/>
          </g>
        </g>
      </svg>
      <span class="scenic-caption">Pip · Woodland Songbird</span>
    </div>

  </div>
</aside>
