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

  /* VIEWPORT */
  /* OUTER WRAPPER – CREATES LEFT & RIGHT GAP */
.carousel-center-wrapper {
  width: 100%;
  display: flex;
  justify-content: center;
}

/* CENTER MOVING ZONE */
.badge-row {
  width: 70%;              /* 👈 controls center width */
  overflow: hidden;
  padding: 3rem 0;
  background: #f8fafc;
}

/* TRACK */
.badge-track {
  display: flex;
  width: max-content;
  animation: scroll 20s linear infinite;
}

/* BOTTLES */
.badge {
  flex: 0 0 auto;
  margin: 0 40px;
}

.badge img {
  width: 110px;
  display: block;
}
/* FULL WIDTH BACKGROUND */
.bottle-section {
  display: grid;
  grid-template-columns: 1fr 70% 1fr; /* ← your drawn boundaries */
  align-items: center;
  background: #f8fafc;
  padding: 4rem 0;
}

/* BLOCKED SIDES */
.side-block {
  height: 100%;
}

/* CENTER WINDOW = HARD MASK */
.center-window {
  overflow: hidden;          /* ← THIS creates the vertical walls */
  position: relative;
}

/* MOVING TRACK */
.bottle-track {
  display: flex;
  width: max-content;
  animation: scroll 20s linear infinite;
}

/* BOTTLES */
.bottle {
  flex: 0 0 auto;
  margin: 0 80px;
}

.bottle img {
  width: 7vh;
  display: block;
}

/* INFINITE LOOP */
@keyframes scroll {
  from {
    transform: translateX(0);
  }
  to {
    transform: translateX(-50%);
  }
}

/* INFINITE */
@keyframes scroll {
  from {
    transform: translateX(0);
  }
  to {
    transform: translateX(-50%);
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
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>

      <!-- SET 2 (duplicate) -->
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>
      <div class="bottle"><img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png"></div>

    </div>
  </div>

  <!-- RIGHT BLOCK (visual wall) -->
  <div class="side-block"></div>

</section>

