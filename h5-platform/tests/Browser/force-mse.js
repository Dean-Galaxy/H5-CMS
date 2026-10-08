// Testing-only capability selection. Real Hls 1.7.3, MSE, media responses and decoders remain intact.
(() => {
  const original = HTMLMediaElement.prototype.canPlayType;
  window.qa06NativeHlsBefore = original.call(document.createElement('video'), 'application/vnd.apple.mpegurl');
  HTMLMediaElement.prototype.canPlayType = function(type) {
    return type === 'application/vnd.apple.mpegurl' ? '' : original.call(this, type);
  };
})();
