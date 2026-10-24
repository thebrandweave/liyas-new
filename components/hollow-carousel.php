<section class="hollow-text-section d-flex justify-content-center align-items-center">
  <div class="hollow-carousel" id="hollowCarousel">
    <h1 class="hollow-text">
      <span class="outline-letter">L</span>
      <span class="outline-letter">I</span>
      <span class="outline-letter">Y</span>
      <span class="outline-letter">A</span>
      <span class="outline-letter">S</span>
    </h1>
  </div>
</section>

<script>
  // Clone the content dynamically for continuous loop
  const carousel = document.getElementById("hollowCarousel");
  const textHTML = carousel.innerHTML;

  // Append copies until the width is enough to fill the screen
  for (let i = 0; i < 10; i++) {
    carousel.insertAdjacentHTML("beforeend", textHTML);
  }
</script>


<style>
.hollow-text-section {
  /* height: 40vh; */
  background-color: transparent;
  overflow: hidden;
  position: relative;
  padding-bottom: 20px;
}

.hollow-carousel {
  display: flex;
  white-space: nowrap;
  animation: scrollText 10s linear infinite;
}

.hollow-text {
  font-size: 18em;
  font-weight: 900;
  letter-spacing: -0.1em;
  color: transparent;
  display: inline-block;
  margin: 0 1em;
}

.outline-letter {
  display: inline-block;
  -webkit-text-stroke: 2px rgb(0, 0, 0);
  transition: -webkit-text-stroke 0.3s ease;
}

.outline-letter:hover {
  -webkit-text-stroke: 2px #3b8ebf; /* Changes outline color only */
}

/* Continuous scroll animation */
@keyframes scrollText {
  0% {
    transform: translateX(0);
  }
  100% {
    transform: translateX(-50%);
  }
}

</style>