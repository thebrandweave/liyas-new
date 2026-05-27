document.addEventListener('DOMContentLoaded', function () {
    // Optional: If you have a separate file for the modal, you could inject it here.
    // For now, we assume the modal HTML is on the page.
});

async function openProductModal(productId) {
    const modalElement = document.getElementById('productDetailModal');
    if (!modalElement) {
        console.error('Product detail modal not found on the page.');
        return;
    }
    
    const modal = new bootstrap.Modal(modalElement);
    
    // Show a loading state
    const modalBody = modalElement.querySelector('.modal-body');
    modalBody.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    modal.show();

    try {
        const base = (typeof BASE_URL !== 'undefined' ? BASE_URL : '').replace(/\/$/, '');
        const response = await fetch(`${base}/get_product_details.php?id=${productId}`);
        const result = await response.json();

        if (result.success) {
            populateModal(modalElement, result.data);
        } else {
            modalBody.innerHTML = `<p class="text-danger p-4">Error: ${result.message}</p>`;
        }
    } catch (error) {
        console.error('Failed to fetch product details:', error);
        modalBody.innerHTML = '<p class="text-danger p-4">Could not load product details. Please try again later.</p>';
    }
}

function populateModal(modal, data) {
    const { product, reviews } = data;
    const isUserLoggedIn = (typeof userIsLoggedIn !== 'undefined' && userIsLoggedIn);

    let reviewsHtml = '<h5>Reviews</h5><hr>';
    if (reviews.length > 0) {
        reviewsHtml += '<ul class="list-unstyled">';
        reviews.forEach(review => {
            const stars = '⭐'.repeat(review.rating) + '☆'.repeat(5 - review.rating);
            reviewsHtml += `
                <li class="mb-3">
                    <strong>${escapeHtml(review.full_name)}</strong>
                    <span class="ms-2">${stars}</span>
                    <p class="mb-0 mt-1">${escapeHtml(review.review_text)}</p>
                    <small class="text-muted">${new Date(review.created_at).toLocaleDateString()}</small>
                </li>
            `;
        });
        reviewsHtml += '</ul>';
    } else {
        reviewsHtml += '<p>No reviews yet. Be the first to review this product!</p>';
    }

    let reviewFormHtml = '';
    if (isUserLoggedIn) {
        reviewFormHtml = `
            <div class="mt-4">
                <h5>Write a Review</h5>
                <form id="reviewForm" onsubmit="submitReview(event, ${product.product_id})">
                    <div class="mb-3">
                        <label for="rating" class="form-label">Rating</label>
                        <select id="rating" name="rating" class="form-select" required>
                            <option value="5">5 Stars</option>
                            <option value="4">4 Stars</option>
                            <option value="3">3 Stars</option>
                            <option value="2">2 Stars</option>
                            <option value="1">1 Star</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="review_text" class="form-label">Your Review</label>
                        <textarea id="review_text" name="review_text" class="form-control" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit Review</button>
                    <div id="review-form-message" class="mt-2"></div>
                </form>
            </div>
        `;
    } else {
        const loginHref = (typeof BASE_URL !== 'undefined' ? BASE_URL.replace(/\/$/, '') : '') + '/login/';
        reviewFormHtml = `<p class="mt-4">Please <a href="${loginHref}">log in</a> to write a review.</p>`;
    }

    const modalBodyHtml = `
        <div class="row">
            <div class="col-md-5 text-center">
                <img src="${product.image_url}" alt="${escapeHtml(product.name)}" class="img-fluid rounded mb-3">
            </div>
            <div class="col-md-7">
                <h3>${escapeHtml(product.name)}</h3>
                <div class="price">₹${parseFloat(product.price).toFixed(2)}</div>
                <p>${escapeHtml(product.description)}</p>
                <button class="add-btn mt-3"
                    data-product-id="${product.product_id}"
                    data-name="${escapeHtml(product.name)}"
                    data-price="${parseFloat(product.price).toFixed(2)}"
                    data-image="${product.image_url}">
                    Add to Cart
                </button>
            </div>
        </div>
        <hr class="my-4">
        <div class="row">
            <div class="col-12">
                ${reviewsHtml}
                ${reviewFormHtml}
            </div>
        </div>
    `;

    modal.querySelector('.modal-body').innerHTML = modalBodyHtml;

    // We update the title as well, though the modal-title element is in the header
    const modalTitle = modal.querySelector('.modal-title');
    if(modalTitle) modalTitle.textContent = product.name;

    // --- NEW: Event listener for the modal's add button ---
    const addButton = modal.querySelector('.add-btn');
    if (addButton) {
        addButton.addEventListener('click', (e) => {
            e.stopPropagation(); // Keep modal open

            if (typeof userIsLoggedIn !== 'undefined' && userIsLoggedIn) {
                const productData = {
                    id: addButton.dataset.productId,
                    name: addButton.dataset.name,
                    price: parseFloat(addButton.dataset.price),
                    image: addButton.dataset.image,
                    quantity: 1
                };
                
                // Dispatch a custom event that cart.js can listen for
                document.dispatchEvent(new CustomEvent('addToCartFromModal', { detail: productData }));

            } else {
                const base = (typeof BASE_URL !== 'undefined' ? BASE_URL : '').replace(/\/$/, '');
                const returnTo = encodeURIComponent(window.location.pathname + window.location.search);
                window.location.href = base + '/login/?redirect=' + returnTo;
            }
        });
    }
}

async function submitReview(event, productId) {
    event.preventDefault();
    const form = event.target;
    const messageDiv = document.getElementById('review-form-message');
    const submitButton = form.querySelector('button[type="submit"]');

    messageDiv.textContent = '';
    submitButton.disabled = true;

    const formData = new FormData(form);
    formData.append('product_id', productId);

    try {
        const response = await fetch('../submit_review.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            messageDiv.className = 'text-success';
            messageDiv.textContent = result.message;
            form.reset();
            // Optionally, you could hide the form after successful submission
            form.style.display = 'none';
        } else {
            messageDiv.className = 'text-danger';
            messageDiv.textContent = result.message;
            submitButton.disabled = false;
        }
    } catch (error) {
        console.error('Failed to submit review:', error);
        messageDiv.className = 'text-danger';
        messageDiv.textContent = 'An unexpected error occurred. Please try again.';
        submitButton.disabled = false;
    }
}


function escapeHtml(unsafe) {
    return unsafe
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}
