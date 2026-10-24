<?php

/**

 * Sustainability Section component (Scroll-Triggered Text Highlight)

 * - Updated to include a visual "pledge/slogan icon" near the title.

 */

?>

<section class="sustainability-section">

    <div class="pinned-content">

        <h1 class="scroll-text" id="scroll-text">

            <span class="slogan-icon" aria-hidden="true"><i class="fas fa-hand-holding-box"></i></span>

            Sustainability – A Drop Towards Change<br><br>

            We believe every bottle can make a difference. From eco-friendly production to reusable packaging, our mission is to ensure every sip supports a sustainable tomorrow.

        </h1>

    </div>

</section>



<style>

    .sustainability-section {

        position: relative;

        height: 300vh; /* Creates space for the effect */

    }



    .pinned-content {

        position: sticky;

        top: 0;

        height: 100vh;

        /* Reduced opacity on overlay slightly to let background image show better */

        background: linear-gradient(135deg, rgba(255, 255, 255, 0.9) 0%, rgba(248, 250, 252, 0.9) 100%), 

                    url('https://images.unsplash.com/photo-1559827260-dc66d52bef19?w=1600&q=80') center/cover;

        display: flex;

        justify-content: center;

        align-items: center;

        overflow: hidden;

    }



    .scroll-text {

        width: min(90%, 1200px);

        font-size: clamp(2em, 4vw, 3em);

        text-align: center;

        line-height: 2;

        font-weight: 600;

        color: #3b8ebf;

        margin: 0 auto;

        text-transform: uppercase;

        position: relative; /* Needed for the icon positioning */

    }



    .scroll-text span {

        display: inline-block;

        color: #3b8ebf;

        transition: color 0.4s ease, transform 0.3s ease, text-shadow 0.4s ease;

        letter-spacing: 2px;

    }

    

    /* New Slogan Icon Styling */

    .slogan-icon {

        display: block; /* Make it take up its own space */

        margin: 0 auto 10px;

        font-size: 1.5em; /* Scale the icon relative to text size */

        color: #10b981; /* Use the highlight color for emphasis */

        filter: drop-shadow(0 0 5px rgba(16, 185, 129, 0.4));

        transition: none !important; /* Exclude icon from word-by-word animation */

    }

    

    /* Ensure the icon itself is not treated as an animatable word span */

    .slogan-icon i {

        color: inherit;

        display: block;

    }



    .scroll-text span.show-down {

        color: #10b981; /* green */

        transform: translateY(-2px);

        text-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);

    }



    .scroll-text span.show-up {

        color: #3b8ebf; /* back to cyan */

        transform: translateY(0);

        text-shadow: none;

    }

</style>



<script>

    document.addEventListener('DOMContentLoaded', function() {

        const textElement = document.getElementById("scroll-text");

        const section = document.querySelector(".sustainability-section");



        // Extract all text content, including the icon wrapper

        const fullContent = textElement.innerHTML; 

        

        // Preserve the icon and text structure

        const textOnly = textElement.textContent;



        // Split words only from the text content

        const words = textOnly.split(/\s+/).filter(Boolean);



        // Separate the icon element

        const iconHtml = textElement.querySelector('.slogan-icon').outerHTML;

        

        // Wrap words in spans, excluding the icon and putting it back in front

        const spannedWords = words.map(w => `<span>${w}</span>`).join(" ");

        textElement.innerHTML = iconHtml + spannedWords;



        const spans = textElement.querySelectorAll("span");

        

        // The first span is the icon, skip it in the words list for the effect

        const animatableSpans = Array.from(spans).filter(span => !span.classList.contains('slogan-icon'));

        

        let lastScrollY = window.scrollY;



        window.addEventListener("scroll", () => {

            const rect = section.getBoundingClientRect();

            const scrollDirection = window.scrollY > lastScrollY ? "down" : "up";

            lastScrollY = window.scrollY;



            // Section active: while it's pinned

            if (rect.top <= 0 && rect.bottom > window.innerHeight) {

                const totalScrollable = rect.height - window.innerHeight;

                const currentScroll = Math.abs(rect.top);

                const progress = Math.min(currentScroll / totalScrollable, 1);

                // Use the length of only the words for calculation

                const index = Math.floor(progress * animatableSpans.length); 



                animatableSpans.forEach((span, i) => {

                    if (scrollDirection === "down") {

                        if (i <= index) {

                            span.classList.add("show-down");

                            span.classList.remove("show-up");

                        }

                    } else {

                        if (i >= index) {

                            span.classList.add("show-up");

                            span.classList.remove("show-down");

                        }

                    }

                });

            }

        });

    });

</script>