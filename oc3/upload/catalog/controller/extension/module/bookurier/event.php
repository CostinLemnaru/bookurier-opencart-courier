<?php
require_once DIR_SYSTEM . 'library/extension/bookurier_bootstrap.php';

use Opencart\System\Library\Extension\Bookurier\OrderAwbService;
use Opencart\System\Library\Extension\Bookurier\SamedayLockerCheckoutService;
use Opencart\System\Library\Extension\Bookurier\Settings;

class ControllerExtensionModuleBookurierEvent extends Controller {
	public function autoAwb(&$route, &$args, &$output) {
		if (!(int)$this->config->get('module_bookurier_status') || !(int)$this->config->get('module_bookurier_auto_awb_enabled')) {
			return;
		}

		$order_id = (int)($args[0] ?? 0);
		$order_status_id = (int)($args[1] ?? 0);
		$allowed_status_ids = $this->normalizeStatusIds($this->config->get('module_bookurier_auto_awb_status_ids'));

		if ($order_id <= 0 || $order_status_id <= 0 || !in_array($order_status_id, $allowed_status_ids, true)) {
			return;
		}

		$service = new OrderAwbService($this->registry);

		try {
			$service->generateForOrder($order_id, 'auto', true);
		} catch (\Throwable $exception) {
			$service->log('error', 'Auto AWB generation failed after order history update.', [
				'route' => $route,
				'order_id' => $order_id,
				'order_status_id' => $order_status_id,
				'message' => $exception->getMessage(),
				'event_code' => Settings::EVENT_AUTO_AWB_CODE
			]);
		}
	}

	public function shippingMethodAfter(&$route, &$data, &$output) {
		if (!(int)$this->config->get('module_bookurier_status') || !(int)$this->config->get('module_bookurier_sameday_enabled')) {
			return;
		}

		$this->load->language('extension/module/bookurier/sameday_locker');

		$config = [
			'optionsUrl' => str_replace('&amp;', '&', $this->url->link('extension/module/bookurier/sameday_locker/options', '', true)),
			'saveUrl' => str_replace('&amp;', '&', $this->url->link('extension/module/bookurier/sameday_locker/save', '', true)),
			'quotePrefix' => 'sameday_locker.',
			'textChooseLocker' => $this->language->get('text_choose_locker'),
			'textSearchLocker' => $this->language->get('text_search_locker'),
			'textSelectLocker' => $this->language->get('text_select_locker'),
			'textLockerSaved' => $this->language->get('text_locker_saved'),
			'textLockerLoading' => $this->language->get('text_locker_loading'),
			'textLockerEmpty' => $this->language->get('text_locker_empty'),
			'textLockerRequired' => $this->language->get('text_locker_required'),
			'textLockerUnavailable' => $this->language->get('text_locker_unavailable')
		];

		$script = <<<'HTML'
<script>
(function() {
  if (window.BookurierSamedayLockerOc3 && typeof window.BookurierSamedayLockerOc3.toggle === 'function') {
    window.setTimeout(window.BookurierSamedayLockerOc3.toggle, 0);
    return;
  }

  window.BookurierSamedayLockerOc3 = {};

  var config = __CONFIG__;
  var state = {
    lockers: [],
    loaded: false,
    selectedLockerId: ''
  };

  function isSamedayQuote(code) {
    return typeof code === 'string' && code.indexOf(config.quotePrefix) === 0;
  }

  function getContainer() {
    return document.querySelector('#collapse-shipping-method .panel-body');
  }

  function getSelectedShippingRadio() {
    var container = getContainer();

    return container ? container.querySelector('input[name="shipping_method"]:checked') : null;
  }

  function getSamedayRadio() {
    var container = getContainer();

    if (!container) {
      return null;
    }

    var radios = container.querySelectorAll('input[name="shipping_method"]');

    for (var i = 0; i < radios.length; i++) {
      if (isSamedayQuote(radios[i].value)) {
        return radios[i];
      }
    }

    return null;
  }

  function ensurePanel() {
    var radio = getSamedayRadio();

    if (!radio) {
      return null;
    }

    var panel = document.getElementById('bookurier-sameday-locker-panel');

    if (panel) {
      return panel;
    }

    var anchor = radio.closest('.radio');

    if (!anchor) {
      return null;
    }

    panel = document.createElement('div');
    panel.id = 'bookurier-sameday-locker-panel';
    panel.className = 'well well-sm hidden';
    panel.innerHTML = '' +
      '<label for="bookurier-sameday-locker-search">' + escapeHtml(config.textChooseLocker) + '</label>' +
      '<input type="search" id="bookurier-sameday-locker-search" class="form-control input-sm bookurier-locker-search" placeholder="' + escapeHtml(config.textSearchLocker) + '" autocomplete="off" />' +
      '<select class="form-control input-sm bookurier-locker-select" size="8" style="margin-top:8px; min-height:100px;"></select>' +
      '<div class="help-block bookurier-locker-status" style="margin-bottom:0;"></div>';

    anchor.insertAdjacentElement('afterend', panel);
    panel.querySelector('.bookurier-locker-search').addEventListener('input', renderCurrentLockers);
    panel.querySelector('.bookurier-locker-select').addEventListener('change', onLockerChange);

    return panel;
  }

  function getStatusElement() {
    var panel = ensurePanel();

    return panel ? panel.querySelector('.bookurier-locker-status') : null;
  }

  function setStatus(message, isError) {
    var element = getStatusElement();

    if (!element) {
      return;
    }

    element.textContent = message || '';
    element.className = 'help-block bookurier-locker-status' + (message ? (isError ? ' text-danger' : ' text-success') : '');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderCurrentLockers() {
    var panel = ensurePanel();

    if (!panel) {
      return;
    }

    var search = panel.querySelector('.bookurier-locker-search');
    var select = panel.querySelector('.bookurier-locker-select');
    var query = search ? search.value.trim().toLowerCase() : '';
    var lockers = Array.isArray(state.lockers) ? state.lockers : [];
    var filtered = lockers.filter(function(locker) {
      return !query || String(locker.search_text || '').indexOf(query) !== -1;
    });
    var html = '<option value="">' + escapeHtml(config.textSelectLocker) + '</option>';

    filtered.forEach(function(locker) {
      var lockerId = String(locker.locker_id || '');
      var selected = lockerId === String(state.selectedLockerId || '') ? ' selected="selected"' : '';
      html += '<option value="' + escapeHtml(lockerId) + '"' + selected + '>' + escapeHtml(locker.label || '') + '</option>';
    });

    select.innerHTML = html;

    if (!filtered.length) {
      setStatus(config.textLockerEmpty, true);
    } else if (!state.selectedLockerId) {
      setStatus('', false);
    }
  }

  function persistSelection(lockerId, silent) {
    var radio = getSelectedShippingRadio();

    if (!radio || !isSamedayQuote(radio.value) || !lockerId) {
      return Promise.resolve(false);
    }

    if (!silent) {
      setStatus(config.textLockerLoading, false);
    }

    return fetch(config.saveUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: new URLSearchParams({
        quote_code: radio.value,
        locker_id: lockerId
      }).toString()
    })
      .then(function(response) { return response.json(); })
      .then(function(payload) {
        if (payload.error) {
          throw new Error(payload.error);
        }

        state.selectedLockerId = String(payload.locker_id || lockerId);
        setStatus(payload.success || config.textLockerSaved, false);

        return true;
      })
      .catch(function(error) {
        if (!silent) {
          setStatus(error && error.message ? error.message : config.textLockerRequired, true);
        }

        return false;
      });
  }

  function loadLockers() {
    var panel = ensurePanel();

    if (!panel) {
      return Promise.resolve();
    }

    setStatus(config.textLockerLoading, false);

    return fetch(config.optionsUrl, {
      credentials: 'same-origin'
    })
      .then(function(response) { return response.json(); })
      .then(function(payload) {
        if (payload.error) {
          throw new Error(payload.error);
        }

        state.lockers = Array.isArray(payload.lockers) ? payload.lockers : [];
        state.selectedLockerId = String(payload.selected_locker_id || '');
        state.loaded = true;
        renderCurrentLockers();

        if (!state.lockers.length) {
          setStatus(config.textLockerUnavailable, true);
          return;
        }

        if (state.selectedLockerId) {
          setStatus(config.textLockerSaved, false);
          return;
        }

        setStatus(config.textLockerRequired, true);
      })
      .catch(function(error) {
        state.loaded = false;
        setStatus(error && error.message ? error.message : config.textLockerUnavailable, true);
      });
  }

  function togglePanel() {
    var panel = ensurePanel();
    var selectedRadio = getSelectedShippingRadio();

    if (!panel) {
      return;
    }

    var isActive = !!selectedRadio && isSamedayQuote(selectedRadio.value);

    panel.classList.toggle('hidden', !isActive);

    if (isActive) {
      if (!state.loaded) {
        loadLockers();
        return;
      }

      renderCurrentLockers();
    }
  }

  function onLockerChange(event) {
    var lockerId = String(event.target.value || '');

    if (!lockerId) {
      state.selectedLockerId = '';
      setStatus(config.textLockerRequired, true);
      return;
    }

    persistSelection(lockerId, false);
  }

  document.addEventListener('change', function(event) {
    if (event.target.matches('#collapse-shipping-method input[name="shipping_method"]')) {
      togglePanel();
    }
  });

  document.addEventListener('click', function(event) {
    if (!event.target || event.target.id !== 'button-shipping-method') {
      return;
    }

    var selectedRadio = getSelectedShippingRadio();

    if (!selectedRadio || !isSamedayQuote(selectedRadio.value)) {
      return;
    }

    if (!state.selectedLockerId) {
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      togglePanel();
      setStatus(config.textLockerRequired, true);
    }
  }, true);

  window.BookurierSamedayLockerOc3.toggle = togglePanel;
  togglePanel();
})();
</script>
HTML;

		$output .= str_replace('__CONFIG__', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $script);
	}

	public function orderAddAfter(&$route, &$args, &$output) {
		if (!(int)$this->config->get('module_bookurier_status') || !(int)$this->config->get('module_bookurier_sameday_enabled')) {
			return;
		}

		$order_id = (int)$output;
		$order_data = isset($args[0]) && is_array($args[0]) ? $args[0] : [];

		if ($order_id <= 0) {
			return;
		}

		try {
			$service = new SamedayLockerCheckoutService($this->registry);
			$service->bindSelectionToOrder($order_id, $order_data);
		} catch (\Throwable $exception) {
			$logger = new OrderAwbService($this->registry);
			$logger->log('error', 'SameDay locker selection could not be bound to the order.', [
				'route' => $route,
				'order_id' => $order_id,
				'message' => $exception->getMessage(),
				'event_code' => Settings::EVENT_ORDER_BIND_LOCKER_CODE
			]);
		}
	}

	private function normalizeStatusIds($status_ids) {
		if (!is_array($status_ids)) {
			return [];
		}

		return array_values(array_unique(array_map('intval', $status_ids)));
	}
}
