// Global state
let allPaintings = [];
let displayedPaintings = [];
let currentPage = 1;
let isLoading = false;
let hasMore = true;
let currentModalIndex = -1;
let currentVariantIndex = 0;
let currentPaintingVariants = [];
let maxImageHeight = 0;
let adminClickCount = 0;
let adminClickTimer = null;

/**
 * Convert image URL to thumbnail URL by inserting _thumb before extension
 * Example: img/gallery/painting.jpg -> img/gallery/painting_thumb.jpg
 */
function getThumbnailUrl(imageUrl) {
  if (!imageUrl) return imageUrl;
  // Match the last dot before the extension
  return imageUrl.replace(/\.(jpg|jpeg|png|gif|webp)$/i, '_thumb.$1');
}

// Artist configuration (read from HTML meta tags)
const ARTIST_CONFIG = {
  email: document.querySelector('meta[name="artist-email"]')?.content || 'anne@herzfabrik.com',
  domain: document.querySelector('meta[name="site-domain"]')?.content || 'herzfabrik.com'
};

// DOM Elements
const gallery = document.getElementById('gallery');
const authorSection = document.getElementById('author-section');
const authorToggle = document.getElementById('author-toggle');
const flashlightOverlay = document.getElementById('flashlight-overlay');
const modal = document.getElementById('modal');
const modalImage = document.getElementById('modal-image');
const modalTitle = document.getElementById('modal-title');
const modalDescription = document.getElementById('modal-description');
const modalMeta = document.getElementById('modal-meta');
const modalVariants = document.getElementById('modal-variants');
const loadMoreBtn = document.getElementById('load-more-btn');
const loadMoreContainer = document.getElementById('load-more-container');

// Initialize
document.addEventListener('DOMContentLoaded', () => {
  // Update email addresses in HTML from meta tag
  const emailElements = document.querySelectorAll('.mail-email');
  emailElements.forEach(el => {
    if (ARTIST_CONFIG.email) {
      el.textContent = ARTIST_CONFIG.email;
    }
  });
  
  // Update author photo to use thumbnail
  const authorPhoto = document.getElementById('author-photo');
  if (authorPhoto && authorPhoto.src) {
    authorPhoto.src = getThumbnailUrl(authorPhoto.src);
  }
  
  // Update imprint information from meta tags
  const imprintAddress = document.querySelector('meta[name="imprint-address"]')?.content || '';
  const imprintPostalCode = document.querySelector('meta[name="imprint-postal-code"]')?.content || '';
  const imprintCity = document.querySelector('meta[name="imprint-city"]')?.content || '';
  const imprintPhone = document.querySelector('meta[name="imprint-phone"]')?.content || '';
  
  // Extract artist name from meta tag or title tag
  let artistName = '';
  const artistNameMeta = document.querySelector('meta[name="artist-name"]');
  if (artistNameMeta && artistNameMeta.content) {
    artistName = artistNameMeta.content.trim();
  } else {
    // Fallback: try to extract from title tag
    const titleTag = document.querySelector('title');
    if (titleTag) {
      const titleText = titleTag.textContent || '';
      const match = titleText.match(/Herzfabrik\s*-\s*(.+)/);
      if (match) {
        artistName = match[1].trim();
      }
    }
  }
  
  // Update imprint elements if they exist (on imprint.html and dataprivacy.html)
  document.querySelectorAll('#imprint-name, #imprint-name-2').forEach(el => {
    el.textContent = artistName || '';
  });
  document.querySelectorAll('#imprint-address, #imprint-address-2').forEach(el => {
    el.textContent = imprintAddress || '';
  });
  document.querySelectorAll('#imprint-postal-code, #imprint-postal-code-2').forEach(el => {
    el.textContent = imprintPostalCode || '';
  });
  document.querySelectorAll('#imprint-city, #imprint-city-2').forEach(el => {
    el.textContent = imprintCity || '';
  });
  document.querySelectorAll('#imprint-email').forEach(el => {
    el.textContent = ARTIST_CONFIG.email || '';
  });
  document.querySelectorAll('#imprint-phone').forEach(el => {
    el.textContent = imprintPhone || '';
  });
  
  // Update domain links and text from meta tag
  const domainUrl = 'https://' + ARTIST_CONFIG.domain;
  const domainText = ARTIST_CONFIG.domain.charAt(0).toUpperCase() + ARTIST_CONFIG.domain.slice(1);
  const siteTitleLinks = document.querySelectorAll('.site-title');
  siteTitleLinks.forEach(link => {
    link.href = domainUrl;
    if (link.hasAttribute('data-domain-text')) {
      link.textContent = domainText;
    }
  });
  
  // Only initialize gallery-related features if gallery exists (not on imprint/privacy pages)
  if (gallery) {
    initAuthorSection();
    initAuthorVariantReveal();
    initFlashlight();
    initModal();
    initInfiniteScroll();
    initMailButtons();
    initHerzfabrikLinks();
    initAdminButton();
    initFooterHeart();
    initFlyingHearts();
    initTitlePulse();
    loadPaintings().then(() => {
      // Check for deep link parameter
      const urlParams = new URLSearchParams(window.location.search);
      const paintingName = urlParams.get('painting');
      if (paintingName) {
        // Find painting by filename
        const index = displayedPaintings.findIndex(p => {
          const filename = p.filename || p.imageUrl.split('/').pop();
          return filename.toLowerCase().includes(paintingName.toLowerCase());
        });
        if (index !== -1) {
          openModal(index);
        }
      }
    });
  } else {
    // On imprint/privacy pages, only initialize mail buttons and admin button if they exist
    initMailButtons();
    initAdminButton();
  }
});

// Initialize Admin Button
function initAdminButton() {
  const adminBtn = document.getElementById('admin-btn');
  const adminBtnModal = document.getElementById('admin-btn-modal');
  const adminArtistBtn = document.getElementById('admin-artist-btn');
  
  // Check if admin flag is set in localStorage
  const isAdmin = typeof Storage !== 'undefined' && localStorage.getItem('admin') === 'true';
  
  // Function to handle admin button click
  function handleAdminClick(e) {
    e.preventDefault();
    e.stopPropagation(); // Prevent modal close
    
    // Get current URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    
    // Build admin URL with preserved parameters
    let adminUrl = '/admin/index.html';
    if (urlParams.toString()) {
      adminUrl += '?' + urlParams.toString();
    }
    
    window.location.href = adminUrl;
  }
  
  // Show and setup main admin button
  if (adminBtn && isAdmin) {
    adminBtn.style.display = 'block';
    adminBtn.addEventListener('click', handleAdminClick);
  }
  
  // Show and setup modal admin button
  if (adminBtnModal && isAdmin) {
    adminBtnModal.style.display = 'block';
    adminBtnModal.addEventListener('click', handleAdminClick);
  }
  
  // Show and setup admin artist button
  if (adminArtistBtn && isAdmin) {
    adminArtistBtn.style.display = 'inline-block';
  }
  
  // Show admin edit buttons for all paintings
  updatePaintingAdminButtons(isAdmin);
}

// Update admin edit buttons for all paintings
function updatePaintingAdminButtons(isAdmin) {
  const adminEditButtons = document.querySelectorAll('.painting-admin-edit-btn');
  adminEditButtons.forEach(btn => {
    btn.style.display = isAdmin ? 'inline-block' : 'none';
  });
}

// Initialize herzfabrik.com links to remove URL params
function initHerzfabrikLinks() {
  const siteTitle = document.querySelector('.site-title');
  const modalUrl = document.querySelector('.modal-url');
  
  if (siteTitle) {
    siteTitle.addEventListener('click', (e) => {
      // Remove params when clicking herzfabrik.com link
      if (window.location.search) {
        e.preventDefault();
        window.history.pushState({}, '', window.location.pathname);
        // If modal is open, close it
        if (modal && modal.classList.contains('active')) {
          closeModal();
        }
        // Then navigate to the link
        window.location.href = siteTitle.href;
      }
    });
  }
  
  if (modalUrl) {
    modalUrl.addEventListener('click', (e) => {
      // Remove params when clicking herzfabrik.com link in modal
      if (window.location.search) {
        e.preventDefault();
        closeModal();
        window.history.pushState({}, '', window.location.pathname);
        // Then navigate to the link
        window.location.href = modalUrl.href;
      }
    });
  }
}

// Author Section Toggle
function initAuthorSection() {
  const authorToggleCollapsed = document.getElementById('author-toggle-collapsed');
  
  // Handle expanded state toggle button (at end of content)
  if (authorToggle) {
    authorToggle.addEventListener('click', () => {
      authorSection.classList.toggle('expanded');
      // Scroll to top when collapsing
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
    });
  }
  
  // Handle collapsed state toggle button (inline in text)
  if (authorToggleCollapsed) {
    authorToggleCollapsed.addEventListener('click', () => {
      authorSection.classList.toggle('expanded');
    });
  }
}

// Initialize variant reveal effect for author portrait
function initAuthorVariantReveal() {
  const wrapper = document.querySelector('.author-image-wrapper');
  if (!wrapper) return;
  
  const variantLayer = wrapper.querySelector('.author-variant-reveal');
  const variantImgSharp = wrapper.querySelector('.author-variant-image-sharp');
  const variantImgBlurred = wrapper.querySelector('.author-variant-image-blurred');
  const revealMask = wrapper.querySelector('.author-reveal-mask');
  
  if (!variantLayer || !variantImgSharp || !variantImgBlurred || !revealMask) return;
  
  // Alternative portrait images management
  // Dynamically detect available alternative images (check up to 10)
  let NUM_ALTERNATIVE_IMAGES = 0;
  
  // Detect available alternative images asynchronously
  (async function detectAlternativeImages() {
    const checkImage = (index) => {
      return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => resolve(true);
        img.onerror = () => resolve(false);
        // Use thumbnail for checking (faster)
        img.src = getThumbnailUrl(`img/upload/artist-alternative-${index}.jpg`);
        // Timeout after 1 second
        setTimeout(() => resolve(false), 1000);
      });
    };
    
    // Check images sequentially up to 10
    for (let i = 1; i <= 10; i++) {
      const exists = await checkImage(i);
      if (exists) {
        NUM_ALTERNATIVE_IMAGES = i;
      } else {
        break;
      }
    }
  })();
  
  // Current index: 0 = standard image, 1 = variant 1, 2 = variant 2, etc.
  let currentAlternativeIndex = 0; // Start with standard image (0)
  
  // Show/hide variant layer
  function showVariantLayer(show) {
    if (show) {
      variantLayer.style.display = 'block';
      variantLayer.style.opacity = '1';
    } else {
      variantLayer.style.opacity = '0';
      // Hide after transition
      setTimeout(() => {
        if (variantLayer.style.opacity === '0') {
          variantLayer.style.display = 'none';
        }
      }, 300);
    }
  }
  
  // Load a specific alternative image
  function loadAlternativeImage(index) {
    // index is 1-based (1, 2, 3, ...)
    const imageUrl = `img/upload/artist-alternative-${index}.jpg`;
    // Use thumbnails for variant reveal effect (list view)
    const thumbUrl = getThumbnailUrl(imageUrl);
    variantImgSharp.src = thumbUrl;
    variantImgBlurred.src = thumbUrl;
  }
  
  // Rotate to next image
  // On desktop: if hovering (variant 1 visible), first click goes to variant 2
  // On mobile: standard -> variant 1 -> variant 2 -> ... -> standard -> variant 1
  function nextAlternativeImage() {
    if (NUM_ALTERNATIVE_IMAGES === 0) return;
    
    // Check if we're on desktop and variant layer is visible (hover effect active)
    // This means variant 1 is being shown via hover, so first click should go to variant 2
    const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    const isVariantLayerVisible = variantLayer.style.display === 'block' && 
                                   parseFloat(variantLayer.style.opacity) > 0;
    const isHoveringOnDesktop = !isTouchDevice && isVariantLayerVisible && currentAlternativeIndex === 0;
    
    if (isHoveringOnDesktop) {
      // On desktop with hover active: skip variant 1 (already shown), go to variant 2
      // Make sure we don't exceed available variants
      currentAlternativeIndex = Math.min(2, NUM_ALTERNATIVE_IMAGES);
    } else {
      // Normal rotation: increment and wrap around
      currentAlternativeIndex = (currentAlternativeIndex + 1) % (NUM_ALTERNATIVE_IMAGES + 1);
    }
    
    if (currentAlternativeIndex === 0) {
      // Show standard image (hide variant layer)
      showVariantLayer(false);
    } else {
      // Show variant image (currentAlternativeIndex is 1-based for variants)
      loadAlternativeImage(currentAlternativeIndex);
      showVariantLayer(true);
      
      if (isTouchDevice) {
        // Show the sharp variant image on mobile (no blur effect)
        variantImgSharp.style.maskImage = 'none';
        variantImgSharp.style.webkitMaskImage = 'none';
        variantImgBlurred.style.display = 'none';
      }
    }
  }
  
  // Detect touch device
  const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
  
  // Initialize: Show standard image (hide variant layer)
  if (NUM_ALTERNATIVE_IMAGES > 0) {
    // Preload first variant image
    loadAlternativeImage(1);
    // But start with standard image hidden
    showVariantLayer(false);
  } else {
    variantLayer.style.display = 'none';
  }
  
  // Function to handle click/touch and trigger animations
  function handlePortraitClick(e) {
    // Rotate to next alternative image on any click/touch within the wrapper
    e.preventDefault();
    e.stopPropagation();
    nextAlternativeImage();
    
    // Trigger heart animation on click/touch
    if (window.createFlyingHeart) {
      window.createFlyingHeart();
    }
    
    // Trigger title pulse animation on click/touch
    if (window.triggerTitlePulse) {
      window.triggerTitlePulse();
    }
  }
  
  // Click handler for desktop
  wrapper.addEventListener('click', handlePortraitClick);
  
  // Touch handler for mobile devices
  wrapper.addEventListener('touchstart', handlePortraitClick, { passive: false });
  
  // Also make photo clickable
  const authorPhoto = document.getElementById('author-photo');
  if (authorPhoto) {
    authorPhoto.style.cursor = 'pointer';
  }
  
  // On touch devices, don't show hover effect
  if (isTouchDevice) {
    // Don't initialize hover effect on mobile
    return;
  }
  
  // Desktop hover effect with extended radius
  let mouseX = 0;
  let mouseY = 0;
  let animationFrame = null;
  const EXTERNAL_RADIUS = 150; // pixels outside the image where effect is still active
  
  // Check if mouse is within activation radius
  function isWithinRadius(clientX, clientY) {
    const rect = wrapper.getBoundingClientRect();
    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;
    const maxDistance = Math.max(rect.width, rect.height) / 2 + EXTERNAL_RADIUS;
    const distance = Math.sqrt(Math.pow(clientX - centerX, 2) + Math.pow(clientY - centerY, 2));
    return distance <= maxDistance;
  }
  
  // Update blur effect based on cursor position
  function updateRevealMask() {
    // Don't show effect if no alternatives are available
    if (NUM_ALTERNATIVE_IMAGES === 0) {
      variantLayer.style.opacity = '0';
      return;
    }
    
    const rect = wrapper.getBoundingClientRect();
    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;
    
    // Check if mouse is within activation radius
    const withinRadius = isWithinRadius(mouseX, mouseY);

    if (!withinRadius) {
      variantLayer.style.opacity = '0';
      variantImgBlurred.style.maskImage = '';
      variantImgBlurred.style.webkitMaskImage = '';
      variantImgBlurred.style.opacity = '0';
      if (animationFrame) {
        cancelAnimationFrame(animationFrame);
        animationFrame = null;
      }
      return;
    }
    
    // Ensure variant layer is visible for hover effect
    variantLayer.style.display = 'block';
    
    // Load the first variant image if we're showing standard image (index 0)
    // This ensures hover effect always shows a variant, regardless of click state
    if (currentAlternativeIndex === 0) {
      loadAlternativeImage(1);
    }
    
    // Calculate position relative to wrapper (can be negative or > 100% if outside)
    const relativeX = mouseX - rect.left;
    const relativeY = mouseY - rect.top;
    
    // Calculate center position as percentage
    const percentX = (relativeX / rect.width) * 100;
    const percentY = (relativeY / rect.height) * 100;
    
    // Create masks for both layers
    const radius = rect.width * 1.0; // 100% of displayed image width
    
    // Sharp center mask: opaque in center, smoothly fades to transparent
    const sharpRadiusPercent = 50; // 50% of the radius is fully opaque (crystal clear)
    const sharpMaskGradient = `radial-gradient(circle ${radius}px at ${percentX}% ${percentY}%, rgba(255,255,255,1) 0%, rgba(255,255,255,1) ${sharpRadiusPercent}%, rgba(255,255,255,0.9) ${sharpRadiusPercent + 5}%, rgba(255,255,255,0.7) ${sharpRadiusPercent + 10}%, rgba(255,255,255,0.5) ${sharpRadiusPercent + 15}%, rgba(255,255,255,0.3) ${sharpRadiusPercent + 20}%, rgba(255,255,255,0.1) ${sharpRadiusPercent + 30}%, rgba(255,255,255,0) ${sharpRadiusPercent + 50}%)`;
    
    // Apply mask: sharp image shows center, smoothly transitions to original image
    variantImgSharp.style.maskImage = sharpMaskGradient;
    variantImgSharp.style.webkitMaskImage = sharpMaskGradient;
    variantImgSharp.style.maskSize = 'cover';
    variantImgSharp.style.webkitMaskSize = 'cover';
    variantImgSharp.style.maskMode = 'alpha';
    variantImgSharp.style.webkitMaskMode = 'alpha';
    variantImgSharp.style.filter = 'none'; // No blur on sharp image - crystal clear
    
    // Hide the blurred layer - we only want the sharp center transitioning to original
    variantImgBlurred.style.maskImage = 'none';
    variantImgBlurred.style.webkitMaskImage = 'none';
    variantImgBlurred.style.opacity = '0';
    
    variantLayer.style.opacity = '1';
    
    animationFrame = requestAnimationFrame(updateRevealMask);
  }
  
  // Global mouse move handler to track mouse position everywhere
  function handleGlobalMouseMove(e) {
    mouseX = e.clientX;
    mouseY = e.clientY;
    if (!animationFrame) {
      updateRevealMask();
    }
  }
  
  // Start tracking mouse movement globally
  document.addEventListener('mousemove', handleGlobalMouseMove);
  
  // Initial check
  updateRevealMask();
}

// Variant Reveal Effect (replaces flashlight)
function initFlashlight() {
  // Check if we're in detail view - if so, disable the effect
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.has('painting')) {
    if (flashlightOverlay) {
      flashlightOverlay.style.display = 'none';
    }
    return;
  }
  
  // Hide flashlight overlay (we'll use a different approach)
  if (flashlightOverlay) {
    flashlightOverlay.style.display = 'none';
  }
  
  // Initialize variant reveal on paintings after they're loaded
  // This will be called from renderPaintings
}

// Load Paintings from API
async function loadPaintings() {
  // Only load paintings if gallery element exists (not on imprint/privacy pages)
  if (!gallery) return Promise.resolve();
  
  if (isLoading || !hasMore) return Promise.resolve();
  
  isLoading = true;
  
  if (loadMoreBtn) {
    loadMoreBtn.disabled = true;
    loadMoreBtn.textContent = 'Lädt...';
  }
  
  try {
    const params = new URLSearchParams({
      page: currentPage,
      perPage: 20
    });
    
    const response = await fetch(`api/paintings.php?${params}`);
    
    if (!response.ok) {
      throw new Error('Fehler beim Laden der Gemälde');
    }
    
    const data = await response.json();
    
    if (data.paintings && data.paintings.length > 0) {
      // Clear loading message if this is the first page
      if (currentPage === 1 && gallery) {
        gallery.innerHTML = '';
      }
      
      displayedPaintings = [...displayedPaintings, ...data.paintings];
      renderPaintings(data.paintings);
      currentPage++;
      hasMore = data.hasMore;
      
      if (loadMoreContainer) {
        loadMoreContainer.style.display = hasMore ? 'block' : 'none';
      }
    } else {
      if (currentPage === 1) {
        showNoResults();
      }
      hasMore = false;
      if (loadMoreContainer) {
        loadMoreContainer.style.display = 'none';
      }
    }
  } catch (error) {
    console.error('Error loading paintings:', error);
    if (gallery) {
      showError('Fehler beim Laden der Gemälde. Bitte versuchen Sie es erneut.');
    }
  } finally {
    isLoading = false;
    if (loadMoreBtn) {
      loadMoreBtn.disabled = false;
      loadMoreBtn.textContent = 'Mehr laden';
    }
  }
}

// Render Paintings
function renderPaintings(paintings) {
  const startIndex = displayedPaintings.length - paintings.length;
  
  paintings.forEach((painting, batchIndex) => {
    const globalIndex = startIndex + batchIndex;
    const wrapper = document.createElement('div');
    wrapper.className = 'painting-wrapper';
    
    const frame = document.createElement('div');
    const frameType = painting.frame_type || 'white';
    frame.className = `painting-frame frame-${frameType}`;
    
    // Container for image reveal effect
    const imageContainer = document.createElement('div');
    imageContainer.className = 'painting-image-container';
    
    const img = document.createElement('img');
    // Use thumbnail for list view (automatically convert imageUrl to thumbnail)
    img.src = getThumbnailUrl(painting.imageUrl);
    img.alt = painting.title || 'Painting';
    img.className = 'painting-image';
    img.loading = 'lazy';
    
    // Add variant reveal layer if variants exist
    if (painting.variants && painting.variants.length > 0) {
      const variantLayer = document.createElement('div');
      variantLayer.className = 'painting-variant-reveal';
      
      // Use thumbnail for variant in list view (automatically convert)
      const variantThumbUrl = getThumbnailUrl(painting.variants[0]);
      
      // Create sharp variant image (center, no blur)
      const variantImgSharp = document.createElement('img');
      variantImgSharp.src = variantThumbUrl; // Use thumbnail for list view
      variantImgSharp.alt = 'Variant';
      variantImgSharp.className = 'painting-variant-image-sharp';
      variantImgSharp.loading = 'lazy';
      
      // Create blurred variant image (edges, with blur)
      const variantImgBlurred = document.createElement('img');
      variantImgBlurred.src = variantThumbUrl; // Use thumbnail for list view
      variantImgBlurred.alt = 'Variant';
      variantImgBlurred.className = 'painting-variant-image-blurred';
      variantImgBlurred.loading = 'lazy';
      
      // Create reveal mask
      const revealMask = document.createElement('div');
      revealMask.className = 'painting-reveal-mask';
      
      variantLayer.appendChild(variantImgSharp);
      variantLayer.appendChild(variantImgBlurred);
      variantLayer.appendChild(revealMask);
      imageContainer.appendChild(img);
      imageContainer.appendChild(variantLayer);
      
      // Initialize variant reveal effect
      initVariantReveal(imageContainer, painting.variants, globalIndex);
    } else {
      imageContainer.appendChild(img);
    }
    
    // Add sold indicator (red dot) if painting is sold
    if (painting.sold) {
      const soldIndicator = document.createElement('div');
      soldIndicator.className = 'painting-sold-indicator';
      soldIndicator.setAttribute('aria-label', 'Verkauft');
      soldIndicator.setAttribute('title', 'Verkauft');
      soldIndicator.setAttribute('tabindex', '0');
      // Prevent click from propagating to image container
      soldIndicator.addEventListener('click', (e) => {
        e.stopPropagation();
        // Toggle tooltip visibility on click
        soldIndicator.classList.toggle('tooltip-active');
      });
      // Hide tooltip when clicking outside
      document.addEventListener('click', (e) => {
        if (!soldIndicator.contains(e.target)) {
          soldIndicator.classList.remove('tooltip-active');
        }
      });
      imageContainer.appendChild(soldIndicator);
    }
    
    frame.appendChild(imageContainer);
    wrapper.appendChild(frame);
    
    const info = document.createElement('div');
    info.className = 'painting-info';
    
    const title = document.createElement('div');
    title.className = 'painting-title';
    title.textContent = painting.title || 'Ohne Titel';
    
    const dimensions = document.createElement('div');
    dimensions.className = 'painting-dimensions';
    if (painting.width && painting.height) {
      dimensions.textContent = `${painting.width} × ${painting.height} cm`;
    }
    
    // Admin edit button (only visible in admin mode)
    const adminEditBtn = document.createElement('a');
    adminEditBtn.className = 'painting-admin-edit-btn';
    adminEditBtn.textContent = 'Bearbeiten';
    adminEditBtn.style.display = 'none';
    const filename = painting.filename || painting.imageUrl.split('/').pop();
    const baseName = filename.replace(/\.[^/.]+$/, '');
    adminEditBtn.href = `/admin/index.html?painting=${encodeURIComponent(baseName)}`;
    
    info.appendChild(title);
    info.appendChild(dimensions);
    info.appendChild(adminEditBtn);
    wrapper.appendChild(info);
    
    // Show admin button if in admin mode
    const isAdmin = typeof Storage !== 'undefined' && localStorage.getItem('admin') === 'true';
    if (isAdmin) {
      adminEditBtn.style.display = 'inline-block';
    }
    
    // Separate click handlers: image opens with variant, text opens without variant
    imageContainer.addEventListener('click', (e) => {
      e.stopPropagation();
      // If painting has variants, open with first variant (variant=1, since 0 is main image)
      if (painting.variants && painting.variants.length > 0) {
        openModal(globalIndex, 1);
      } else {
        openModal(globalIndex);
      }
    });
    
    info.addEventListener('click', (e) => {
      e.stopPropagation();
      // Text click opens main version without variant
      openModal(globalIndex);
    });
    
    if (gallery) {
      gallery.appendChild(wrapper);
    }
  });
}

// Initialize variant reveal effect for a painting
function initVariantReveal(container, variants, paintingIndex) {
  const variantLayer = container.querySelector('.painting-variant-reveal');
  const variantImgSharp = container.querySelector('.painting-variant-image-sharp');
  const variantImgBlurred = container.querySelector('.painting-variant-image-blurred');
  const revealMask = container.querySelector('.painting-reveal-mask');
  
  if (!variantLayer || !variantImgSharp || !variantImgBlurred || !revealMask) return;
  
  // Use thumbnail URLs (already set in renderPaintings, but ensure they're set)
  // The src is already set to thumbnail in renderPaintings, so we don't need to change it here
  
  // Detect touch device
  const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
  
  // On touch devices, hide variant and show normal image only
  if (isTouchDevice) {
    variantLayer.style.display = 'none';
    return; // Exit early, no hover effect needed
  }
  
  // Desktop hover effect with extended radius
  let mouseX = 0;
  let mouseY = 0;
  let animationFrame = null;
  const EXTERNAL_RADIUS = 150; // pixels outside the image where effect is still active
  
  // Check if mouse is within activation radius
  function isWithinRadius(clientX, clientY) {
    const rect = container.getBoundingClientRect();
    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;
    const maxDistance = Math.max(rect.width, rect.height) / 2 + EXTERNAL_RADIUS;
    const distance = Math.sqrt(Math.pow(clientX - centerX, 2) + Math.pow(clientY - centerY, 2));
    return distance <= maxDistance;
  }
  
  // Update blur effect based on cursor position
  function updateRevealMask() {
    const rect = container.getBoundingClientRect();
    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;
    
    // Check if mouse is within activation radius
    const withinRadius = isWithinRadius(mouseX, mouseY);
    
        
    if (!withinRadius) {
      variantLayer.style.opacity = '0';
      variantImgBlurred.style.maskImage = '';
      variantImgBlurred.style.webkitMaskImage = '';
      variantImgBlurred.style.opacity = '0';
      if (animationFrame) {
        cancelAnimationFrame(animationFrame);
        animationFrame = null;
      }
      return;
    }
    
    // Calculate position relative to container (can be negative or > 100% if outside)
    const relativeX = mouseX - rect.left;
    const relativeY = mouseY - rect.top;
    
    // Calculate center position as percentage
    const percentX = (relativeX / rect.width) * 100;
    const percentY = (relativeY / rect.height) * 100;
    
    // Create masks for both layers
    const radius = rect.width * 1.0; // 100% of displayed image width
    
    // Sharp center mask: opaque in center, smoothly fades to transparent
    // This creates a single circle that transitions to the original image
    const sharpRadiusPercent = 50; // 50% of the radius is fully opaque (crystal clear) - increased to show more variant
    // Smooth transition from center to edges, revealing original image
    const sharpMaskGradient = `radial-gradient(circle ${radius}px at ${percentX}% ${percentY}%, rgba(255,255,255,1) 0%, rgba(255,255,255,1) ${sharpRadiusPercent}%, rgba(255,255,255,0.9) ${sharpRadiusPercent + 5}%, rgba(255,255,255,0.7) ${sharpRadiusPercent + 10}%, rgba(255,255,255,0.5) ${sharpRadiusPercent + 15}%, rgba(255,255,255,0.3) ${sharpRadiusPercent + 20}%, rgba(255,255,255,0.1) ${sharpRadiusPercent + 30}%, rgba(255,255,255,0) ${sharpRadiusPercent + 50}%)`;
    
    // Apply mask: sharp image shows center, smoothly transitions to original image
    variantImgSharp.style.maskImage = sharpMaskGradient;
    variantImgSharp.style.webkitMaskImage = sharpMaskGradient;
    variantImgSharp.style.maskSize = 'cover';
    variantImgSharp.style.webkitMaskSize = 'cover';
    variantImgSharp.style.maskMode = 'alpha';
    variantImgSharp.style.webkitMaskMode = 'alpha';
    variantImgSharp.style.filter = 'none'; // No blur on sharp image - crystal clear
    
    // Hide the blurred layer - we only want the sharp center transitioning to original
    variantImgBlurred.style.maskImage = 'none';
    variantImgBlurred.style.webkitMaskImage = 'none';
    variantImgBlurred.style.opacity = '0';
    
    variantLayer.style.opacity = '1';
    
    animationFrame = requestAnimationFrame(updateRevealMask);
  }
  
  // Global mouse move handler to track mouse position everywhere
  function handleGlobalMouseMove(e) {
    mouseX = e.clientX;
    mouseY = e.clientY;
    if (!animationFrame) {
      updateRevealMask();
    }
  }
  
  // Start tracking mouse movement globally
  document.addEventListener('mousemove', handleGlobalMouseMove);
  
  // Initial check
  updateRevealMask();
  
  // Clean up on container removal (if needed)
  const observer = new MutationObserver(() => {
    if (!document.body.contains(container)) {
      document.removeEventListener('mousemove', handleGlobalMouseMove);
      observer.disconnect();
      if (animationFrame) {
        cancelAnimationFrame(animationFrame);
      }
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });
}

// Show No Results
function showNoResults() {
  if (gallery) {
    gallery.innerHTML = '<div class="no-results">Keine Gemälde gefunden. Versuchen Sie eine andere Suche.</div>';
  }
}

// Show Error
function showError(message) {
  if (gallery) {
    gallery.innerHTML = `<div class="no-results">${message || 'Fehler beim Laden der Gemälde. Bitte versuchen Sie es erneut.'}</div>`;
  }
}

// Modal Functions
function initModal() {
  const closeBtn = document.getElementById('modal-close');
  const prevBtn = document.getElementById('modal-prev');
  const nextBtn = document.getElementById('modal-next');
  
  if (closeBtn) {
    closeBtn.addEventListener('click', closeModal);
  }
  
  if (prevBtn) {
    prevBtn.addEventListener('click', () => navigateModal(-1));
  }
  
  if (nextBtn) {
    nextBtn.addEventListener('click', () => navigateModal(1));
  }
  
  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        closeModal();
      }
    });
  }
  
  // Admin access: 10 fast clicks on modal image, or click to switch variants
  const modalImageLink = document.getElementById('modal-image-link');
  if (modalImageLink) {
    modalImageLink.addEventListener('click', (e) => {
      e.preventDefault(); // Prevent opening image in new tab
      e.stopPropagation(); // Prevent modal close
      
      // Clear existing timer
      if (adminClickTimer) {
        clearTimeout(adminClickTimer);
      }
      
      // Increment click count
      adminClickCount++;
      
      // If 10 clicks reached, redirect to admin
      if (adminClickCount >= 10) {
        const painting = displayedPaintings[currentModalIndex];
        if (painting) {
          const filename = painting.filename || painting.imageUrl.split('/').pop();
          const baseName = filename.replace(/\.[^/.]+$/, '');
          window.location.href = `/admin/index.html?painting=${encodeURIComponent(baseName)}`;
        }
        adminClickCount = 0;
        return;
      }
      
      // If there are variants, switch to next variant on click
      if (currentPaintingVariants.length > 1) {
        navigateModal(1);
        adminClickCount = 0; // Reset admin counter when switching variants
        return;
      }
      
      // Reset counter after 1 second of inactivity
      adminClickTimer = setTimeout(() => {
        adminClickCount = 0;
      }, 1000);
    });
  }
  
  // Initialize zoom effect for modal image (will be re-initialized when modal opens)
  
  // Reset click count when modal closes - will be set up after closeModal is defined
  
  // Keyboard navigation
  document.addEventListener('keydown', (e) => {
    if (!modal.classList.contains('active')) return;
    
    if (e.key === 'Escape') {
      closeModal();
    } else if (e.key === 'ArrowLeft') {
      navigateModal(-1);
    } else if (e.key === 'ArrowRight') {
      navigateModal(1);
    }
  });
  
  // Handle browser back/forward buttons
  window.addEventListener('popstate', (e) => {
    const urlParams = new URLSearchParams(window.location.search);
    const paintingName = urlParams.get('painting');
    if (paintingName && displayedPaintings.length > 0) {
      const index = displayedPaintings.findIndex(p => {
        const filename = p.filename || p.imageUrl.split('/').pop();
        return filename.toLowerCase().includes(paintingName.toLowerCase());
      });
      if (index !== -1) {
        // variant will be read from URL in openModal
        openModal(index);
      }
    } else if (modal && modal.classList.contains('active')) {
      closeModal();
    }
  });
}

function openModal(index, variantIndex = null) {
  if (index < 0 || index >= displayedPaintings.length) return;
  
  currentModalIndex = index;
  const painting = displayedPaintings[index];
  
  if (!modal || !painting) return;
  
  // Build variants array: main image + variants
  currentPaintingVariants = [painting.imageUrl];
  if (painting.variants && painting.variants.length > 0) {
    currentPaintingVariants = currentPaintingVariants.concat(painting.variants);
  }
  
  // Set variant index: from parameter, URL param, or default to 0
  if (variantIndex !== null) {
    currentVariantIndex = variantIndex;
  } else {
    // Check URL for variant parameter
    const urlParams = new URLSearchParams(window.location.search);
    const urlVariant = urlParams.get('variant');
    if (urlVariant !== null) {
      const variantNum = parseInt(urlVariant, 10);
      if (!isNaN(variantNum) && variantNum >= 0 && variantNum < currentPaintingVariants.length) {
        currentVariantIndex = variantNum;
      } else {
        currentVariantIndex = 0;
      }
    } else {
      currentVariantIndex = 0;
    }
  }
  
  maxImageHeight = 0; // Reset max height for new painting
  
  // Reset container min-height when opening modal
  const imageContainer = document.querySelector('.modal-image-container');
  if (imageContainer) {
    imageContainer.style.minHeight = '';
    // Remove any existing sold indicator from image container (we'll show it in metadata instead)
    const existingIndicator = imageContainer.querySelector('.painting-sold-indicator, .modal-sold-text');
    if (existingIndicator) {
      existingIndicator.remove();
    }
  }
  
  // Update URL with deep link
  const filename = painting.filename || painting.imageUrl.split('/').pop();
  const baseName = filename.replace(/\.[^/.]+$/, '');
  let newUrl = `${window.location.pathname}?painting=${encodeURIComponent(baseName)}`;
  if (currentVariantIndex > 0) {
    newUrl += `&variant=${currentVariantIndex}`;
  }
  window.history.pushState({ painting: baseName, variant: currentVariantIndex }, '', newUrl);
  
  updateModalImage();
  
  // Set title
  if (modalTitle) {
    modalTitle.textContent = painting.title || 'Ohne Titel';
  }
  
  // Set description
  if (modalDescription) {
    modalDescription.textContent = painting.description || '';
  }
  
  // Set metadata
  if (modalMeta) {
    modalMeta.innerHTML = '';
    
    if (painting.date) {
      const dateItem = createMetaItem('Datum', painting.date);
      modalMeta.appendChild(dateItem);
    }
    
    if (painting.width && painting.height) {
      const sizeItem = createMetaItem('Größe', `${painting.width} × ${painting.height} cm`);
      modalMeta.appendChild(sizeItem);
    }
    
    if (painting.tags) {
      const tagsItem = createMetaItem('Tags', painting.tags);
      modalMeta.appendChild(tagsItem);
    }
    
    if (painting.sold) {
      const soldItem = createMetaItem('Status', 'Verkauft');
      modalMeta.appendChild(soldItem);
    }
  }
  
  // Set variants thumbnails
  if (modalVariants) {
    modalVariants.innerHTML = '';
    
    if (painting.variants && painting.variants.length > 0) {
      const grid = document.createElement('div');
      grid.className = 'variants-grid';
      
      // Add main image as first thumbnail (use thumbnail for thumbnail display)
      const mainThumbnail = document.createElement('div');
      mainThumbnail.className = 'variant-thumbnail';
      if (currentVariantIndex === 0) {
        mainThumbnail.classList.add('active');
      }
      const mainImg = document.createElement('img');
      // Use thumbnail for thumbnail display in modal (automatically convert)
      mainImg.src = getThumbnailUrl(painting.imageUrl);
      mainImg.alt = 'Hauptansicht';
      mainThumbnail.appendChild(mainImg);
      mainThumbnail.addEventListener('click', () => {
        currentVariantIndex = 0;
        // Update URL - remove variant parameter for main image
        const painting = displayedPaintings[currentModalIndex];
        if (painting) {
          const filename = painting.filename || painting.imageUrl.split('/').pop();
          const baseName = filename.replace(/\.[^/.]+$/, '');
          const newUrl = `${window.location.pathname}?painting=${encodeURIComponent(baseName)}`;
          window.history.pushState({ painting: baseName }, '', newUrl);
        }
        updateModalImage();
        updateThumbnailSelection();
      });
      grid.appendChild(mainThumbnail);
      
      painting.variants.forEach((variantUrl, idx) => {
        const thumbnail = document.createElement('div');
        thumbnail.className = 'variant-thumbnail';
        if (currentVariantIndex === idx + 1) {
          thumbnail.classList.add('active');
        }
        
        const img = document.createElement('img');
        // Use thumbnail for variant thumbnail display in modal (automatically convert)
        img.src = getThumbnailUrl(variantUrl);
        img.alt = 'Variant view';
        
        thumbnail.appendChild(img);
        thumbnail.addEventListener('click', () => {
          currentVariantIndex = idx + 1;
          // Update URL with variant parameter
          const painting = displayedPaintings[currentModalIndex];
          if (painting) {
            const filename = painting.filename || painting.imageUrl.split('/').pop();
            const baseName = filename.replace(/\.[^/.]+$/, '');
            let newUrl = `${window.location.pathname}?painting=${encodeURIComponent(baseName)}`;
            if (currentVariantIndex > 0) {
              newUrl += `&variant=${currentVariantIndex}`;
            }
            window.history.pushState({ painting: baseName, variant: currentVariantIndex }, '', newUrl);
          }
          updateModalImage();
          updateThumbnailSelection();
        });
        
        grid.appendChild(thumbnail);
      });
      
      modalVariants.appendChild(grid);
    }
  }
  
  // Show/hide navigation buttons (for variants)
  const prevBtn = document.getElementById('modal-prev');
  const nextBtn = document.getElementById('modal-next');
  
  if (prevBtn) {
    prevBtn.style.display = currentPaintingVariants.length > 1 ? 'flex' : 'none';
  }
  
  if (nextBtn) {
    nextBtn.style.display = currentPaintingVariants.length > 1 ? 'flex' : 'none';
  }
  
  // Set display first, then trigger transition
  modal.classList.add('opening');
  document.body.style.overflow = 'hidden';
  
  // Trigger transition by adding active class after display is set
  requestAnimationFrame(() => {
    modal.classList.add('active');
    modal.classList.remove('opening');
  });
  
  // Update mail button visibility
  const mailBtnModal = document.getElementById('mail-btn-modal');
  const mailBtnList = document.getElementById('mail-btn-list');
  if (mailBtnModal) {
    mailBtnModal.style.display = 'inline-flex';
  }
  if (mailBtnList) {
    mailBtnList.style.display = 'none';
  }
}

function updateModalImage() {
  if (modalImage && currentPaintingVariants.length > 0) {
    const imageUrl = currentPaintingVariants[currentVariantIndex];
    modalImage.src = imageUrl;
    modalImage.alt = 'Gemälde';
    
    // Show/hide variant hint based on whether a variant is displayed
    const variantHint = document.getElementById('modal-variant-hint');
    if (variantHint) {
      variantHint.style.display = currentVariantIndex > 0 ? 'flex' : 'none';
    }
    
    // Update the link to the raw image
    const modalImageLink = document.getElementById('modal-image-link');
    if (modalImageLink) {
      modalImageLink.href = imageUrl;
    }
    
    // Track image height when it loads - only use rendered height in modal
    const updateImageHeight = function() {
      const imageContainer = document.querySelector('.modal-image-container');
      if (imageContainer && modalImage && modal.classList.contains('active')) {
        // Use offsetHeight which respects CSS constraints (like max-height: 70vh)
        // Wait a bit for the image to render properly
        setTimeout(() => {
          const imageHeight = modalImage.offsetHeight;
          if (imageHeight > 0 && imageHeight > maxImageHeight) {
            maxImageHeight = imageHeight;
            imageContainer.style.minHeight = maxImageHeight + 'px';
          }
        }, 50);
      }
    };
    
    // Check if image is already loaded (cached)
    if (modalImage.complete && modalImage.naturalHeight > 0) {
      updateImageHeight();
    } else {
      modalImage.onload = updateImageHeight;
    }
  }
}

function updateThumbnailSelection() {
  const thumbnails = document.querySelectorAll('.variant-thumbnail');
  thumbnails.forEach((thumb, idx) => {
    if (idx === currentVariantIndex) {
      thumb.classList.add('active');
    } else {
      thumb.classList.remove('active');
    }
  });
}

function closeModal() {
  // Reset admin click counter
  adminClickCount = 0;
  if (adminClickTimer) {
    clearTimeout(adminClickTimer);
    adminClickTimer = null;
  }
  
  if (modal) {
    // Remove active class to trigger closing transition
    modal.classList.remove('active');
    
    // Wait for transition to complete before hiding modal
    setTimeout(() => {
      // Only hide if modal is still not active (in case it was reopened)
      if (!modal.classList.contains('active')) {
        modal.classList.remove('opening');
        document.body.style.overflow = '';
      }
    }, 300); // Match transition duration (300ms)
  }
  
  currentModalIndex = -1;
  currentVariantIndex = 0;
  maxImageHeight = 0; // Reset max height
  
  // Reset container min-height
  const imageContainer = document.querySelector('.modal-image-container');
  if (imageContainer) {
    imageContainer.style.minHeight = '';
  }
  
  // Update mail button visibility
  const mailBtnModal = document.getElementById('mail-btn-modal');
  const mailBtnList = document.getElementById('mail-btn-list');
  if (mailBtnModal) {
    mailBtnModal.style.display = 'none';
  }
  if (mailBtnList) {
    mailBtnList.style.display = 'flex';
  }
  
  // Remove all params from URL
  window.history.pushState({}, '', window.location.pathname);
}

function navigateModal(direction) {
  // Navigate through variants instead of paintings
  if (currentPaintingVariants.length <= 1) return;
  
  currentVariantIndex += direction;
  
  if (currentVariantIndex < 0) {
    currentVariantIndex = currentPaintingVariants.length - 1;
  } else if (currentVariantIndex >= currentPaintingVariants.length) {
    currentVariantIndex = 0;
  }
  
  // Update URL with variant parameter
  const painting = displayedPaintings[currentModalIndex];
  if (painting) {
    const filename = painting.filename || painting.imageUrl.split('/').pop();
    const baseName = filename.replace(/\.[^/.]+$/, '');
    let newUrl = `${window.location.pathname}?painting=${encodeURIComponent(baseName)}`;
    if (currentVariantIndex > 0) {
      newUrl += `&variant=${currentVariantIndex}`;
    }
    window.history.pushState({ painting: baseName, variant: currentVariantIndex }, '', newUrl);
  }
  
  updateModalImage();
  updateThumbnailSelection();
}

function createMetaItem(label, value) {
  const item = document.createElement('div');
  item.className = 'meta-item';
  
  const labelSpan = document.createElement('span');
  labelSpan.className = 'meta-label';
  labelSpan.textContent = `${label}:`;
  
  const valueSpan = document.createElement('span');
  valueSpan.textContent = value;
  
  item.appendChild(labelSpan);
  item.appendChild(valueSpan);
  
  return item;
}

// Infinite Scroll Detection
function initInfiniteScroll() {
  let scrollTimeout;
  
  window.addEventListener('scroll', () => {
    clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(() => {
      if (isLoading || !hasMore) return;
      
      // Get the load more button position
      if (loadMoreContainer) {
        const rect = loadMoreContainer.getBoundingClientRect();
        const windowHeight = window.innerHeight || document.documentElement.clientHeight;
        
        // If button is visible or near the bottom (within 500px)
        if (rect.top <= windowHeight + 500) {
          loadPaintings();
        }
      }
    }, 100);
  }, { passive: true });
}

// Load More Button
if (loadMoreBtn) {
  loadMoreBtn.addEventListener('click', () => {
    loadPaintings();
  });
}

// Mail Button Functionality
function initMailButtons() {
  const mailBtnList = document.getElementById('mail-btn-list');
  const mailBtnModal = document.getElementById('mail-btn-modal');
  
  // List view mail button (no painting reference)
  if (mailBtnList) {
    mailBtnList.addEventListener('click', (e) => {
      e.preventDefault();
      const subject = encodeURIComponent('Anfrage zu Herzfabrik');
      const body = encodeURIComponent('Hallo,\n\nich interessiere mich für Ihre Kunstwerke.\n\nMit freundlichen Grüßen');
      window.location.href = `mailto:${ARTIST_CONFIG.email}?subject=${subject}&body=${body}`;
    });
  }
  
  // Modal mail button (with painting reference)
  if (mailBtnModal) {
    mailBtnModal.addEventListener('click', (e) => {
      e.preventDefault();
      const painting = displayedPaintings[currentModalIndex];
      if (painting) {
        const paintingTitle = painting.title || 'Gemälde';
        const paintingUrl = window.location.origin + window.location.pathname + '?painting=' + encodeURIComponent((painting.filename || painting.imageUrl.split('/').pop()).replace(/\.[^/.]+$/, ''));
        const subject = encodeURIComponent(`Anfrage zu: ${paintingTitle}`);
        const body = encodeURIComponent(`Hallo,\n\nich interessiere mich für das Gemälde "${paintingTitle}".\n\nLink: ${paintingUrl}\n\nMit freundlichen Grüßen`);
        window.location.href = `mailto:${ARTIST_CONFIG.email}?subject=${subject}&body=${body}`;
      }
    });
  }
}

// Footer Heart - Scroll to Top
function initFooterHeart() {
  const footerHeart = document.getElementById('footer-heart');
  const footerVita = document.getElementById('footer-vita');
  const scrollToTop = () => {
    window.scrollTo({
      top: 0,
      behavior: 'smooth'
    });
  };
  
  if (footerHeart) {
    footerHeart.addEventListener('click', scrollToTop);
  }
  
  if (footerVita) {
    footerVita.addEventListener('click', scrollToTop);
  }
}

// Flying Hearts Animation - synchronized with heartbeat
function initFlyingHearts() {
  // Create container for flying hearts
  let heartsContainer = document.getElementById('flying-hearts-container');
  if (!heartsContainer) {
    heartsContainer = document.createElement('div');
    heartsContainer.id = 'flying-hearts-container';
    heartsContainer.className = 'flying-hearts-container';
    document.body.appendChild(heartsContainer);
  }
  
  // Function to create a flying heart from profile picture
  function createFlyingHeart() {
    const heart = document.createElement('div');
    heart.className = 'flying-heart';
    
    // Get author photo position
    const authorPhoto = document.getElementById('author-photo');
    if (!authorPhoto) return; // Don't create heart if photo doesn't exist
    
    const photoRect = authorPhoto.getBoundingClientRect();
    
    // Start from further up and left in the profile picture
    const startX = photoRect.left + photoRect.width / 4; // Left side (1/4 from left)
    const startY = photoRect.top + photoRect.height / 5; // Upper 1/5 of the image (further up)
    
    // Add very small random offset for slight variation
    const randomOffsetX = (Math.random() - 0.5) * 15; // ±7.5px horizontal variation
    const randomOffsetY = (Math.random() - 0.5) * 15; // ±7.5px vertical variation
    
    heart.style.left = (startX + randomOffsetX) + 'px';
    heart.style.top = (startY + randomOffsetY) + 'px';
    
    // Size - double size (60-100px)
    const size = 60 + Math.random() * 40; // 60-100px
    heart.style.width = size + 'px';
    heart.style.height = size + 'px';
    
    // Animation duration - slower (5-7 seconds)
    const duration = 5 + Math.random() * 2; // 5-7 seconds
    
    // Create unique animation name for this heart
    const animationId = 'fly-heart-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    
    // Create custom keyframes for this heart - flying more up and right, growing larger
    const styleSheet = document.getElementById('flying-hearts-styles') || (() => {
      const style = document.createElement('style');
      style.id = 'flying-hearts-styles';
      document.head.appendChild(style);
      return style;
    })();
    
    // Calculate end position: more up and right (stronger vertical movement)
    const endX = window.innerWidth + 100; // End off-screen right
    const endY = -window.innerHeight * 0.8; // End much higher up (80% of screen height above)
    const horizontalDistance = endX - (startX + randomOffsetX);
    const verticalDistance = endY - (startY + randomOffsetY);
    
    const keyframes = `
      @keyframes ${animationId} {
        0% {
          opacity: 0;
          transform: translate(0, 0) scale(0.3) rotate(0deg);
        }
        15% {
          opacity: 1;
          transform: translate(${horizontalDistance * 0.1}px, ${verticalDistance * 0.15}px) scale(0.6) rotate(90deg);
        }
        50% {
          opacity: 1;
          transform: translate(${horizontalDistance * 0.5}px, ${verticalDistance * 0.6}px) scale(1.0) rotate(180deg);
        }
        85% {
          opacity: 0.9;
          transform: translate(${horizontalDistance * 0.85}px, ${verticalDistance * 0.9}px) scale(1.4) rotate(315deg);
        }
        100% {
          opacity: 0;
          transform: translate(${horizontalDistance}px, ${verticalDistance}px) scale(1.6) rotate(360deg);
        }
      }
    `;
    
    // Add keyframes to stylesheet
    const sheet = styleSheet.sheet || styleSheet.styleSheet;
    if (sheet) {
      try {
        sheet.insertRule(keyframes, sheet.cssRules.length);
      } catch (e) {
        // Fallback for older browsers
        styleSheet.appendChild(document.createTextNode(keyframes));
      }
    }
    
    // Apply animation
    heart.style.animation = `${animationId} ${duration}s ease-out forwards`;
    
    heartsContainer.appendChild(heart);
    
    // Remove heart when it leaves the screen or after animation completes
    const checkIfOffScreen = () => {
      const rect = heart.getBoundingClientRect();
      const isOffScreen = rect.right < 0 || rect.bottom < 0 || rect.left > window.innerWidth || rect.top > window.innerHeight;
      
      if (isOffScreen && heart.parentNode) {
        heart.parentNode.removeChild(heart);
        return true;
      }
      return false;
    };
    
    // Check periodically if heart is off screen
    const checkInterval = setInterval(() => {
      if (checkIfOffScreen()) {
        clearInterval(checkInterval);
      }
    }, 100);
    
    // Remove heart after animation completes (backup)
    setTimeout(() => {
      clearInterval(checkInterval);
      if (heart.parentNode) {
        heart.parentNode.removeChild(heart);
      }
    }, duration * 1000);
  }
  
  // Export createFlyingHeart function so it can be called from click handlers
  // Hearts are now triggered manually by clicking on the portrait photo
  window.createFlyingHeart = createFlyingHeart;
  
  // Pause animation when modal is open
  const modal = document.getElementById('modal');
  if (modal) {
    let isPaused = false;
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
          if (modal.classList.contains('active')) {
            isPaused = true;
          } else {
            isPaused = false;
          }
        }
      });
    });
    
    observer.observe(modal, {
      attributes: true,
      attributeFilter: ['class']
    });
  }
}

// Initialize title pulse animation - export function to trigger pulse
function initTitlePulse() {
  // Export function to trigger title pulse animation
  window.triggerTitlePulse = function() {
    const siteTitles = document.querySelectorAll('.site-title');
    
    siteTitles.forEach(title => {
      // Remove any existing pulse class to restart animation
      title.classList.remove('pulse-once');
      
      // Force reflow to restart animation
      void title.offsetWidth;
      
      // Add pulse class to trigger animation
      title.classList.add('pulse-once');
      
      // Remove class after animation completes (16 seconds)
      setTimeout(() => {
        title.classList.remove('pulse-once');
      }, 16000);
    });
  };
}

