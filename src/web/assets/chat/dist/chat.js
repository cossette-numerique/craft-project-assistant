/* Project Assistant chat UI. Vanilla JS, no build step. */
(function () {
  'use strict';

  const root = document.getElementById('pa-chat');
  if (!root) {
    return;
  }

  const config = JSON.parse(root.dataset.config || '{}');
  const t = (message, params) => Craft.t('project-assistant', message, params);
  const messagesEl = root.querySelector('.pa-messages');
  const form = root.querySelector('.pa-composer');
  const input = root.querySelector('#pa-input');
  const sendBtn = form ? form.querySelector('button[type=submit]') : null;
  const ticketBtn = form ? form.querySelector('.pa-ticket-btn') : null;

  const CATEGORY_LABELS = {
    feature_request: 'Feature request',
    bug: 'Bug',
    configuration_change: 'Configuration change',
    content_help: 'Help with content',
    question: 'Question',
  };
  const PRIORITY_LABELS = { low: 'Low', normal: 'Normal', high: 'High', urgent: 'Urgent' };

  let busy = false;
  let currentSteps = null;
  let statusEl = null;

  // -- Helpers ---------------------------------------------------------------

  function el(tag, attrs, children) {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([key, value]) => {
      if (value === null || value === undefined || value === false) {
        return;
      }
      if (key === 'text') {
        node.textContent = value;
      } else if (key === 'html') {
        node.innerHTML = value; // only ever server-sanitized HTML
      } else if (key.startsWith('on')) {
        node.addEventListener(key.slice(2), value);
      } else {
        node.setAttribute(key, value === true ? '' : value);
      }
    });
    (children || []).forEach((child) => child && node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child));
    return node;
  }

  function scrollToBottom() {
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function clearWelcome() {
    const welcome = messagesEl.querySelector('.pa-welcome');
    if (welcome) {
      welcome.remove();
    }
  }

  function formatCost(value) {
    return '$' + (Number(value) || 0).toFixed(value < 0.1 ? 3 : 2);
  }

  // -- Rendering -------------------------------------------------------------

  function renderUser(item) {
    clearWelcome();
    currentSteps = null;
    const text = item.text || (item.requestTicket ? t('You asked for a ticket') : '');
    const bubble = el('div', { class: 'pa-msg pa-msg--user' }, [el('div', { class: 'pa-msg__body', text: text })]);
    if (item.requestTicket && item.text) {
      bubble.appendChild(el('div', { class: 'pa-msg__meta', text: t('You asked for a ticket') }));
    }
    messagesEl.appendChild(bubble);
  }

  function stepsGroup() {
    if (!currentSteps) {
      const list = el('ol', { class: 'pa-steps__list' });
      const summary = el('summary', {}, [el('span', { class: 'pa-steps__label', text: t('Steps') }), el('span', { class: 'pa-steps__count' })]);
      const details = el('details', { class: 'pa-steps' }, [summary, list]);
      messagesEl.appendChild(details);
      currentSteps = { details, list, count: summary.querySelector('.pa-steps__count'), n: 0 };
    }
    return currentSteps;
  }

  function renderTool(item, live) {
    const group = stepsGroup();
    const li = el('li', { class: 'pa-step' + (live ? ' pa-step--running' : '') + (item.isError ? ' pa-step--error' : ''), 'data-id': item.id || '' }, [
      el('span', { class: 'pa-step__label', text: item.label || item.name }),
    ]);
    group.list.appendChild(li);
    group.n += 1;
    group.count.textContent = ' (' + group.n + ')';
    return li;
  }

  function renderAssistant(item) {
    clearWelcome();
    currentSteps = null;
    messagesEl.appendChild(el('div', { class: 'pa-msg pa-msg--assistant' }, [el('div', { class: 'pa-msg__body pa-prose', html: item.html || '' })]));
  }

  function renderNotice(message, kind) {
    messagesEl.appendChild(el('div', { class: 'pa-notice pa-notice--' + (kind || 'info'), role: kind === 'error' ? 'alert' : null, text: message }));
  }

  function select(name, options, labels, value) {
    return el('select', { name: name }, options.map((option) => el('option', { value: option, selected: option === value }, [t(labels[option] || option)])));
  }

  function field(label, control, hint) {
    return el('div', { class: 'pa-field' }, [el('label', {}, [el('span', { class: 'pa-field__label', text: label }), control]), hint ? el('p', { class: 'pa-field__hint light', text: hint }) : null]);
  }

  function renderTicket(item, editable) {
    clearWelcome();
    currentSteps = null;
    const card = el('section', { class: 'pa-ticket pa-ticket--' + (item.state || 'draft'), 'aria-label': t('Ticket draft for the dev team') });

    if (item.state === 'submitted') {
      fillSubmitted(card, item);
    } else if (item.state === 'dismissed') {
      card.appendChild(el('p', { class: 'light', text: t('Draft dismissed.') + ' — ' + ((item.draft && item.draft.title) || '') }));
    } else {
      fillDraft(card, item.draft || {}, editable);
    }
    messagesEl.appendChild(card);
    return card;
  }

  function fillDraft(card, draft, editable) {
    card.innerHTML = '';
    card.appendChild(el('header', { class: 'pa-ticket__header' }, [
      el('h3', { text: t('Ticket draft for the dev team') }),
      el('span', { class: 'pa-ticket__provider' + (config.ticketProvider.connected ? '' : ' pa-ticket__provider--off'), text: config.ticketProvider.name }),
    ]));
    card.appendChild(el('p', { class: 'light', text: t('Review and edit before sending. Nothing is sent until you confirm.') }));

    const formEl = el('form', { class: 'pa-ticket__form' });
    const disabled = !editable;
    const area = (name, value, rows) => el('textarea', { name: name, rows: rows || 3, disabled: disabled }, [value || '']);

    formEl.appendChild(field(t('Title'), el('input', { type: 'text', name: 'title', value: draft.title || '', maxlength: 200, disabled: disabled, required: true })));
    const row = el('div', { class: 'pa-ticket__row' }, [
      field(t('Category'), select('category', config.categories, CATEGORY_LABELS, draft.category)),
      field(t('Priority'), select('priority', config.priorities, PRIORITY_LABELS, draft.priority || 'normal')),
    ]);
    row.querySelectorAll('select').forEach((s) => (s.disabled = disabled));
    formEl.appendChild(row);
    formEl.appendChild(field(t('What you need'), area('request', draft.request, 3)));
    formEl.appendChild(field(t('What the assistant found'), area('finding', draft.finding, 4)));
    formEl.appendChild(field(t('What was checked'), area('evidence', (draft.evidence || []).join('\n'), 4)));
    formEl.appendChild(field(t('Suggested approach'), area('suggestedApproach', draft.suggestedApproach, 3)));
    if (editable) {
      formEl.appendChild(field(t('Anything to add? (optional)'), area('userNotes', '', 2)));
      const submit = el('button', { type: 'submit', class: 'btn submit', text: t('Send ticket') });
      const dismiss = el('button', { type: 'button', class: 'btn', text: t('Dismiss') });
      formEl.appendChild(el('div', { class: 'pa-ticket__actions' }, [submit, dismiss]));

      formEl.addEventListener('submit', (event) => {
        event.preventDefault();
        const data = new FormData(formEl);
        const fields = {};
        data.forEach((value, key) => (fields[key] = value));
        submit.disabled = dismiss.disabled = true;
        submit.textContent = t('Sending…');
        Craft.sendActionRequest('POST', config.ticketUrl, { data: { conversationId: config.conversationId, fields: fields } })
          .then(({ data: res }) => {
            card.className = 'pa-ticket pa-ticket--submitted';
            fillSubmitted(card, res.item);
          })
          .catch(({ response }) => {
            submit.disabled = dismiss.disabled = false;
            submit.textContent = t('Send ticket');
            Craft.cp.displayError((response && response.data && response.data.message) || t('Something went wrong. The error was logged.'));
          });
      });
      dismiss.addEventListener('click', () => {
        dismiss.disabled = submit.disabled = true;
        Craft.sendActionRequest('POST', config.ticketUrl, { data: { conversationId: config.conversationId, action: 'dismiss' } })
          .then(() => {
            card.className = 'pa-ticket pa-ticket--dismissed';
            card.innerHTML = '';
            card.appendChild(el('p', { class: 'light', text: t('Draft dismissed.') }));
          })
          .catch(() => (dismiss.disabled = submit.disabled = false));
      });
    }
    card.appendChild(formEl);
  }

  function fillSubmitted(card, item) {
    card.innerHTML = '';
    const ticket = item.ticket || {};
    const connected = !!item.connected;
    card.appendChild(el('header', { class: 'pa-ticket__header' }, [
      el('h3', {}, [el('span', { class: 'pa-ticket__check', 'aria-hidden': 'true', text: '✓ ' }), connected ? t('Ticket sent') : t('Ticket saved in the CMS')]),
      el('span', { class: 'pa-ticket__provider' + (connected ? '' : ' pa-ticket__provider--off'), text: item.provider || config.ticketProvider.name }),
    ]));
    if (!connected) {
      card.appendChild(el('p', { class: 'pa-ticket__warning', text: t('The ticket system isn’t connected yet, so the dev team was not notified automatically. Your request is saved with this conversation.') }));
    }
    card.appendChild(el('dl', { class: 'pa-ticket__summary' }, [
      el('dt', { text: t('Reference') }), el('dd', {}, [el('code', { text: item.reference || '—' })]),
      el('dt', { text: t('Title') }), el('dd', { text: ticket.title || '' }),
      el('dt', { text: t('Category') }), el('dd', { text: t(CATEGORY_LABELS[ticket.category] || ticket.category || '') + ' · ' + t(PRIORITY_LABELS[ticket.priority] || ticket.priority || '') }),
    ]));
    if (item.url && connected) {
      card.appendChild(el('p', {}, [el('a', { href: item.url, target: '_blank', rel: 'noopener', class: 'go', text: t('Open ticket') })]));
    }
  }

  // -- Approvals (developer chat) ----------------------------------------------

  const APPROVAL_STATES = {
    approved: 'Approved and run',
    failed: 'Approved, but it failed',
    rejected: 'Rejected: not run',
  };

  function renderApproval(item) {
    clearWelcome();
    currentSteps = null;
    const card = el('section', {
      class: 'pa-approval pa-approval--' + (item.state || 'pending') + (item.destructive ? ' pa-approval--destructive' : ''),
      'data-id': item.id,
      'aria-label': t('Change waiting for approval'),
    });
    fillApproval(card, item);
    messagesEl.appendChild(card);
    return card;
  }

  function fillApproval(card, item) {
    card.innerHTML = '';
    const pending = (item.state || 'pending') === 'pending';
    card.className = 'pa-approval pa-approval--' + (item.state || 'pending') + (item.destructive ? ' pa-approval--destructive' : '');
    card.appendChild(el('header', { class: 'pa-approval__header' }, [
      el('span', { class: 'pa-approval__title', text: pending ? t('Change waiting for approval') : t(APPROVAL_STATES[item.state] || item.state) }),
      el('code', { class: 'pa-approval__tool', text: item.tool || item.name }),
      item.destructive ? el('span', { class: 'pa-approval__badge', text: t('Changes existing data') }) : null,
    ]));
    if (item.label) {
      card.appendChild(el('p', { class: 'pa-approval__label', text: item.label }));
    }
    const args = JSON.stringify(item.input || {}, null, 2);
    card.appendChild(el('details', { class: 'pa-approval__args', open: pending ? true : null }, [
      el('summary', { text: t('Arguments') }),
      el('pre', {}, [el('code', { text: args })]),
    ]));
    if (item.result && !pending) {
      card.appendChild(el('details', { class: 'pa-approval__result' }, [
        el('summary', { text: t('Result') }),
        el('pre', {}, [el('code', { text: item.result })]),
      ]));
    }
    if (pending && !config.readOnly) {
      const approve = el('button', { type: 'button', class: 'btn submit', text: t('Approve') });
      const reject = el('button', { type: 'button', class: 'btn', text: t('Reject') });
      approve.addEventListener('click', () => resolveApproval(item.id, 'approve'));
      reject.addEventListener('click', () => resolveApproval(item.id, 'reject'));
      card.appendChild(el('div', { class: 'pa-approval__actions' }, [approve, reject]));
    }
    card._item = item;
  }

  function updateApproval(id, changes) {
    const card = messagesEl.querySelector('.pa-approval[data-id="' + CSS.escape(id) + '"]');
    if (card && card._item) {
      fillApproval(card, Object.assign({}, card._item, changes));
    }
  }

  function renderItem(item, index, items) {
    switch (item.type) {
      case 'user':
        return renderUser(item);
      case 'assistant':
        return renderAssistant(item);
      case 'tool':
        return renderTool(item, false);
      case 'approval':
        return renderApproval(item);
      case 'ticket': {
        // Only the latest pending draft stays editable.
        const later = items.slice(index + 1).some((i) => i.type === 'ticket');
        return renderTicket(item, !config.readOnly && item.state === 'draft' && !later);
      }
    }
  }

  // -- Sending ---------------------------------------------------------------

  function setBusy(value) {
    busy = value;
    if (input) input.disabled = value;
    if (sendBtn) sendBtn.disabled = value;
    if (ticketBtn) ticketBtn.disabled = value || !config.conversationId;
    if (value) {
      statusEl = el('div', { class: 'pa-status', role: 'status' }, [el('span', { class: 'spinner' }), el('span', { class: 'pa-status__text', text: t('Thinking…') })]);
      messagesEl.appendChild(statusEl);
    } else if (statusEl) {
      statusEl.remove();
      statusEl = null;
    }
    scrollToBottom();
  }

  function setStatus(text) {
    if (statusEl) {
      statusEl.querySelector('.pa-status__text').textContent = text;
      messagesEl.appendChild(statusEl); // keep it last
    }
  }

  function onEvent(event, data) {
    switch (event) {
      case 'conversation':
        if (!config.conversationId) {
          config.conversationId = data.id;
          history.replaceState({}, '', data.url);
          addToHistory(data);
        }
        break;
      case 'thinking':
        setStatus(t('Thinking…'));
        break;
      case 'tool': {
        const li = renderTool({ id: data.id, name: data.name, label: data.label }, true);
        li.dataset.id = data.id;
        setStatus(data.label);
        break;
      }
      case 'tool_done': {
        const li = messagesEl.querySelector('.pa-step[data-id="' + CSS.escape(data.id) + '"]');
        if (li) {
          li.classList.remove('pa-step--running');
          if (data.isError) li.classList.add('pa-step--error');
        }
        break;
      }
      case 'assistant':
        renderAssistant(data);
        break;
      case 'approval':
        renderApproval(data);
        break;
      case 'approval_resolved':
        updateApproval(data.id, { state: data.state, result: data.result });
        break;
      case 'ticket_draft':
        renderTicket({ state: 'draft', draft: data.draft }, true);
        break;
      case 'notice':
        renderNotice(data.message, 'info');
        break;
      case 'error':
        renderNotice(data.message, 'error');
        break;
      case 'done':
        if (data.awaitingApproval) {
          renderNotice(t('Waiting for your approval. Sending a new message instead rejects the pending changes.'), 'info');
        }
        if (data.questionCostUsd) {
          messagesEl.appendChild(el('div', { class: 'pa-cost light', text: t('This question cost about {cost}.', { cost: formatCost(data.questionCostUsd) }) }));
        }
        break;
    }
    if (statusEl) messagesEl.appendChild(statusEl);
    scrollToBottom();
  }

  async function send(message, requestTicket) {
    if (busy) return;
    renderUser({ text: message, requestTicket: requestTicket });
    if (input) input.value = '';
    await streamRequest(config.sendUrl, {
      message: message,
      requestTicket: requestTicket,
      conversationId: config.conversationId,
      mode: config.mode,
    });
  }

  /** Approve or reject a pending change, then stream the rest of the answer. */
  async function resolveApproval(id, decision) {
    if (busy) return;
    const card = messagesEl.querySelector('.pa-approval[data-id="' + CSS.escape(id) + '"]');
    if (card) card.querySelectorAll('button').forEach((b) => (b.disabled = true));
    await streamRequest(config.resolveUrl, { conversationId: config.conversationId, toolUseId: id, decision: decision });
  }

  /** POSTs and reads the server-sent events of an assistant run. */
  async function streamRequest(action, body) {
    setBusy(true);
    try {
      const response = await fetch(Craft.getActionUrl(action), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          'X-CSRF-Token': Craft.csrfTokenValue,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
        credentials: 'same-origin',
      });

      const type = response.headers.get('Content-Type') || '';
      if (!response.ok || !type.includes('text/event-stream')) {
        let messageText = t('Something went wrong. The error was logged.');
        try {
          const json = await response.json();
          messageText = json.message || json.error || messageText;
        } catch (e) { /* not JSON */ }
        renderNotice(messageText, 'error');
        return;
      }

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      for (;;) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        let index;
        while ((index = buffer.indexOf('\n\n')) !== -1) {
          const chunk = buffer.slice(0, index);
          buffer = buffer.slice(index + 2);
          let event = 'message';
          let data = '';
          chunk.split('\n').forEach((line) => {
            if (line.startsWith('event:')) event = line.slice(6).trim();
            else if (line.startsWith('data:')) data += line.slice(5).trim();
          });
          try {
            onEvent(event, data ? JSON.parse(data) : {});
          } catch (e) {
            console.error('Bad assistant event', e, chunk);
          }
        }
      }
    } catch (e) {
      console.error(e);
      renderNotice(t('The connection was interrupted. Reload the page to see the latest state.'), 'error');
    } finally {
      setBusy(false);
      if (input) input.focus();
    }
  }

  function addToHistory(data) {
    const list = document.querySelector('.pa-history ul') || (() => {
      const nav = document.querySelector('.pa-history');
      if (!nav) return null;
      const empty = nav.querySelector('p');
      if (empty) empty.remove();
      const ul = el('ul');
      nav.appendChild(ul);
      return ul;
    })();
    if (!list) return;
    list.querySelectorAll('a.sel').forEach((a) => a.classList.remove('sel'));
    list.prepend(el('li', {}, [el('a', { href: data.url, class: 'sel', 'aria-current': 'page' }, [el('span', { class: 'pa-history__title', text: data.title })])]));
  }

  // -- Init ------------------------------------------------------------------

  (config.transcript || []).forEach(renderItem);
  scrollToBottom();

  if (form) {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const message = input.value.trim();
      if (message) send(message, false);
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        form.requestSubmit();
      }
    });
    if (ticketBtn) {
      ticketBtn.addEventListener('click', () => send(input.value.trim(), true));
    }
    root.querySelectorAll('.pa-example').forEach((button) => {
      button.addEventListener('click', () => {
        if (!input.disabled) send(button.textContent.trim(), false);
      });
    });
    if (!input.disabled) input.focus();
  }
})();
