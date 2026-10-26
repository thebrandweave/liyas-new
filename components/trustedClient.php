<?php
$script_path = $_SERVER['SCRIPT_NAME'];
$script_dir = dirname($script_path);
$path_segments = array_filter(explode('/', $script_dir));
$is_subdirectory = (count($path_segments) > 1);
$asset_base = $is_subdirectory ? '../' : '';
?>

<style>
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  body {
    background-color: #ffffff;
    color: #1e293b;
    line-height: 1.6;
  }

  /* FULL WIDTH BACKGROUND */
  .bottle-section {
    display: grid;
    grid-template-columns: 1fr 70% 1fr;
    align-items: center;
    background: #f8fafc;
    padding: 4rem 0;
    overflow: hidden;
    width: 100%;
  }

  /* BLOCKED SIDES */
  .side-block {
    height: 100%;
  }

  /* CENTER WINDOW = HARD MASK */
  .center-window {
    overflow: hidden;
    position: relative;
    width: 100%;
  }

  /* MOVING TRACK */
  .bottle-track {
    display: flex;
    width: max-content;
    animation: bottleScroll 12s linear infinite;
  }

  /* BOTTLES */
  .bottle {
    flex: 0 0 auto;
    margin: 0 60px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .bottle img {
    height: clamp(70px, 12vh, 100px);
    width: auto;
    max-width: 100%;
    display: block;
    object-fit: contain;
  }

  /* INFINITE LOOP */
  @keyframes bottleScroll {
    from {
      transform: translateX(0);
    }
    to {
      transform: translateX(-50%);
    }
  }

  /* Mobile responsiveness */
  @media (max-width: 900px) {
    .bottle-section {
      grid-template-columns: 5% 90% 5%;
      padding: 3rem 0;
    }
    .bottle {
      margin: 0 40px;
    }
  }

  @media (max-width: 768px) {
    .bottle-section {
      display: block;
      padding: 2.25rem 0;
      position: relative;
    }

    .side-block {
      display: none;
    }

    .center-window {
      width: 100%;
      mask-image: linear-gradient(90deg, transparent 0%, #000 10%, #000 90%, transparent 100%);
      -webkit-mask-image: linear-gradient(90deg, transparent 0%, #000 10%, #000 90%, transparent 100%);
    }

    .bottle {
      margin: 0 24px;
    }

    .bottle img {
      height: clamp(55px, 10vh, 75px);
    }

    .bottle-track {
      animation-duration: 8s;
    }
  }
</style>

<!-- INFINITE BOTTLE CAROUSEL -->
<section class="bottle-section">

  <!-- LEFT BLOCK (visual wall) -->
  <div class="side-block"></div>

  <!-- CENTER MOVING WINDOW -->
  <div class="center-window">
    <div class="bottle-track">

      <!-- SET 1 -->
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>

      <!-- SET 2 (duplicate for seamless loop) -->
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle"></div>

    </div>
  </div>

  <!-- RIGHT BLOCK (visual wall) -->
  <div class="side-block"></div>

</section>
