/**
 * Client-Side Image Compressor for IDEAPAD Seed Fund Portal
 * Uses HTML5 Canvas to compress Aadhaar, PAN, and Photo to target 100 KB – 150 KB.
 * Zero external libraries required.
 */

window.ImageCompressor = {
  TARGET_MIN_BYTES: 100 * 1024, // 100 KB
  TARGET_MAX_BYTES: 150 * 1024, // 150 KB
  MAX_DIMENSION: 1600,

  /**
   * Compresses an image file targeting 100 KB - 150 KB.
   * @param {File} file
   * @returns {Promise<{blob: Blob, file: File, dataUrl: string, originalSize: number, compressedSize: number}>}
   */
  compress: async function (file) {
    if (!file || !file.type.startsWith('image/')) {
      throw new Error('Please select a valid image file (JPEG, PNG, or WebP).');
    }

    const originalSize = file.size;

    // Load image into HTML Image element
    const img = await this.loadImage(file);

    // Initial scale calculation (clamp maximum dimensions)
    let { width, height } = this.calculateDimensions(img.width, img.height, this.MAX_DIMENSION);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';

    // Draw image onto canvas with white background (in case of PNG transparency)
    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(0, 0, width, height);
    ctx.drawImage(img, 0, 0, width, height);

    // Binary search optimization for quality and dimensions
    let minQuality = 0.4;
    let maxQuality = 0.95;
    let bestBlob = null;
    let bestDiff = Infinity;

    for (let iter = 0; iter < 6; iter++) {
      const quality = (minQuality + maxQuality) / 2;
      const blob = await this.canvasToBlob(canvas, 'image/jpeg', quality);

      const size = blob.size;

      // Check if within desired 100KB - 150KB window
      if (size >= this.TARGET_MIN_BYTES && size <= this.TARGET_MAX_BYTES) {
        bestBlob = blob;
        break;
      }

      // Track closest candidate
      const targetMid = 125 * 1024; // 125 KB
      const diff = Math.abs(size - targetMid);
      if (diff < bestDiff) {
        bestDiff = diff;
        bestBlob = blob;
      }

      if (size > this.TARGET_MAX_BYTES) {
        maxQuality = quality - 0.05;
      } else {
        minQuality = quality + 0.05;
      }

      // If quality is bottoming out and file is still too big, downscale canvas
      if (minQuality >= maxQuality && size > this.TARGET_MAX_BYTES && width > 800) {
        width = Math.round(width * 0.8);
        height = Math.round(height * 0.8);
        canvas.width = width;
        canvas.height = height;
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, width, height);
        ctx.drawImage(img, 0, 0, width, height);
        minQuality = 0.5;
        maxQuality = 0.9;
      }
    }

    const compressedBlob = bestBlob || (await this.canvasToBlob(canvas, 'image/jpeg', 0.8));
    const compressedFile = new File([compressedBlob], file.name.replace(/\.[^/.]+$/, "") + ".jpg", {
      type: 'image/jpeg',
      lastModified: Date.now()
    });

    const dataUrl = await this.blobToDataUrl(compressedBlob);

    return {
      blob: compressedBlob,
      file: compressedFile,
      dataUrl: dataUrl,
      originalSize: originalSize,
      compressedSize: compressedBlob.size,
      reductionPercent: Math.round(((originalSize - compressedBlob.size) / originalSize) * 100)
    };
  },

  loadImage: function (file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = (e) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Failed to decode image.'));
        img.src = e.target.result;
      };
      reader.onerror = () => reject(new Error('Failed to read file.'));
      reader.readAsDataURL(file);
    });
  },

  calculateDimensions: function (width, height, maxDim) {
    if (width <= maxDim && height <= maxDim) {
      return { width, height };
    }
    if (width > height) {
      return {
        width: maxDim,
        height: Math.round((height * maxDim) / width)
      };
    } else {
      return {
        width: Math.round((width * maxDim) / height),
        height: maxDim
      };
    }
  },

  canvasToBlob: function (canvas, mimeType, quality) {
    return new Promise((resolve) => {
      canvas.toBlob((blob) => resolve(blob), mimeType, quality);
    });
  },

  blobToDataUrl: function (blob) {
    return new Promise((resolve) => {
      const reader = new FileReader();
      reader.onloadend = () => resolve(reader.result);
      reader.readAsDataURL(blob);
    });
  },

  formatBytes: function (bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  }
};
