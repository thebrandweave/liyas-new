<?php
// Fetch categories
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$litres_stmt = $pdo->query("SELECT DISTINCT litre FROM products WHERE status = 'active' ORDER BY litre ASC");
$available_litres = $litres_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch products with their category name
$products_stmt = $pdo->query("
    SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    WHERE p.status = 'active'
    ORDER BY p.created_at DESC
");
$products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
/* ================= FILTER BAR ================= */
.product-filters {
  display: flex;
  gap: 1rem;
  margin-bottom: 3rem;
  flex-wrap: wrap;
  z-index: 1000 !important;
}

.product-filters input,
.product-filters select {
  padding: 12px 18px;
  border-radius: 30px;
  border: 1px solid #e2e8f0;
  font-size: 0.95rem;
  outline: none;
}

.product-filters input {
  flex: 1;
  min-width: 240px;
}

/* ================= PRODUCTS ================= */
.products-list {
  padding: 4rem 0;
  width: 100%;
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 2.5rem;
  justify-content: center;
  max-width: 1200px;
  margin: 0 auto;
}

/* CARD */
.product-card {
  background: #fff;
  border-radius: 20px;
  padding: 1.5rem 1.5rem 1.25rem;
  text-align: center;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
  position: relative;
  transition: transform 0.3s ease, box-shadow 0.3s ease;
  width: 100%;
}

.product-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 15px 35px rgba(59, 142, 191, 0.15);
}

/* SIZE BADGE */
.size-badge {
  position: absolute;
  top: 14px;
  left: 14px;
  background: #e6fbff;
  color: #0b2e4e;
  font-size: 0.7rem;
  padding: 5px 12px;
  border-radius: 20px;
  font-weight: 600;
}

/* IMAGE WRAPPER */
.product-image-wrap {
  height: 180px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1rem;
}

.product-image-wrap img {
  height: 160px;
  width: auto;
  max-width: 100%;
  object-fit: contain;
}

/* INFO */
.product-info .category {
  font-size: 0.75rem;
  text-transform: uppercase;
  color: #3b8ebf;
  font-weight: 600;
}

.product-info h4 {
  font-size: 1rem;
  margin: 0.5rem 0;
  color: #0f172a;
}

.rating {
  font-size: 0.85rem;
  color: #fbbf24;
}

.by {
  font-size: 0.8rem;
  color: #64748b;
}

.by span {
  font-weight: 600;
}

.price-section {
  margin: 1rem 0;
}

.new-price {
  font-weight: 700;
  color: #0b2e4e;
  font-size: 1.15rem;
  margin: 0;
}

.old-price {
  font-size: 0.85rem;
  text-decoration: line-through;
  color: #94a3b8;
  margin: 0;
}

.add-btn {
  background: #3b8ebf;
  color: #fff;
  border: none;
  padding: 11px 28px;
  border-radius: 30px;
  cursor: pointer;
  font-weight: 600;
  transition: all 0.25s ease;
  min-height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 120px;
}

.add-btn:hover {
  background: #3bb6c4;
  transform: translateY(-2px);
}

/* Visual distinction for the login alternative */
.login-req-btn {
  background: #3b8ebf;
  font-size: 0.85rem;
  padding: 11px 18px;
}
.login-req-btn:hover {
  background: #3bb6c4;
}

.prod-page{
  text-decoration: none;
  display: block;
}

@media (max-width: 768px) {
  .products-list {
    padding: 3rem 0;
  }
  .product-grid {
    gap: 1.5rem;
  }
}

@media (max-width: 575px) {
  .products-list {
    padding: 2.25rem 0;
  }
  .product-grid {
    grid-template-columns: 1fr;
    gap: 1.5rem;
    padding: 0 10px;
  }
  .product-card {
    max-width: 320px;
    margin: 0 auto;
    padding: 1.5rem 1.25rem 1.25rem;
  }
}
</style>

<section class="products-list">
  <div class="container">

    <!-- SEARCH & FILTER
    <div class="product-filters">
      <input type="text" id="searchInput" placeholder="Search bottle..." />

      <select id="litreFilter">
        <option value="">All Sizes</option>
        <?php foreach ($available_litres as $l): ?>
            <option value="<?= $l ?>"><?= $l ?> L</option>
        <?php endforeach; ?>
      </select>

      <select id="categoryFilter">
        <option value="">All Categories</option>
        <?php foreach ($categories as $category): ?>
            <option value="<?= strtolower(htmlspecialchars($category['name'])) ?>"><?= htmlspecialchars($category['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div> -->

    <!-- PRODUCT GRID -->
    <div class="product-grid">

      <?php if (empty($products)): ?>
        <p class="text-center">No products found.</p>
      <?php else: ?>
        <?php foreach ($products as $product): ?>
        <a class="prod-page" href="/products/<?= $product['product_id'] ?>/">  
          <div class="product-card" 
               onclick="if(document.getElementById('productDetailModal')) { event.preventDefault(); } openProductModal(<?= $product['product_id'] ?>)"
               style="cursor: pointer;"
               data-product-id="<?= $product['product_id'] ?>"
               data-name="<?= strtolower(htmlspecialchars($product['name'])) ?>"
               data-litre="<?= htmlspecialchars($product['litre']) ?>"
               data-category="<?= strtolower(htmlspecialchars($product['category_name'])) ?>">

            <div class="product-image-wrap">
              <img src="<?= BASE_URL ?>/admin/uploads/products/<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
            </div>

            <div class="product-info">
              <p class="category"><?= htmlspecialchars($product['category_name']) ?></p>
              <h4><?= htmlspecialchars($product['name']) ?></h4>
              <p class="by">By <span>Liyas</span></p>
              
              <div class="price-section">
                <p class="new-price">₹<?= htmlspecialchars($product['price']) ?>/Case</p>
                <?php if ($product['discount'] > 0): ?>
                    <p class="old-price">₹<?= htmlspecialchars($product['price'] + ($product['price'] * $product['discount'] / 100)) ?></p>
                <?php endif; ?>
              </div>

              <!-- SESSION VERIFICATION: Only render the add action if the user is authenticated -->
              <?php if (isset($_SESSION['user_id'])): ?>
                <button type="button" class="add-btn" onclick="
                  event.preventDefault(); 
                  event.stopPropagation(); 
                  if(window.LiyasCart) { 
                    window.LiyasCart.add({
                      id: '<?= $product['product_id'] ?>',
                      name: '<?= addslashes($product['name']) ?>',
                      price: <?= floatval($product['price']) ?>,
                      image: '<?= BASE_URL ?>/admin/uploads/products/<?= htmlspecialchars($product['image']) ?>'
                    });
                    window.LiyasCart.toggle(true);
                  }
                ">Add</button>
              <?php else: ?>
                <button type="button" class="add-btn login-req-btn" onclick="
                  event.preventDefault();
                  event.stopPropagation();
                  window.location.href = '<?= BASE_URL ?>/login/?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
                ">Add</button>
              <?php endif; ?>

            </div>
          </div>
        </a>
      <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>
</section>

<script>
const searchInput = document.getElementById("searchInput");
const litreFilter = document.getElementById("litreFilter");
const categoryFilter = document.getElementById("categoryFilter");

const productCards = document.querySelectorAll(".product-card");

function filterProducts() {
  const searchValue = searchInput ? searchInput.value.toLowerCase() : '';
  const litreValue = litreFilter ? litreFilter.value : '';
  const categoryValue = categoryFilter ? categoryFilter.value : '';

  productCards.forEach(product => {
    const name = product.dataset.name || '';
    const litre = product.dataset.litre || '';
    const category = product.dataset.category || '';

    const match =
      name.includes(searchValue) &&
      (!litreValue || litre === litreValue) &&
      (!categoryValue || category === categoryValue);

    const wrapper = product.closest(".prod-page");
    if (wrapper) {
      wrapper.style.display = match ? "block" : "none";
    }
  });
}

// Events (safely attached if elements exist)
if (searchInput) searchInput.addEventListener("input", filterProducts);
if (litreFilter) litreFilter.addEventListener("change", filterProducts);
if (categoryFilter) categoryFilter.addEventListener("change", filterProducts);
</script>
