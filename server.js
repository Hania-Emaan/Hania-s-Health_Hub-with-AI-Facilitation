// server.js — ChatterMate API proxy for Hania's Health Hub
// Run from the project root with:
//   node --env-file=.env server.js
//
// XAMPP/Apache continues to serve the PHP website. This Node server only
// provides the protected AI endpoint at http://localhost:3000/ask.
// The API key is read from .env and is never sent to the browser.

'use strict';

const http = require('node:http');

const HOST = '127.0.0.1';
const PORT = 3000;
const API_KEY = process.env.CHATTERMATE_API_KEY;
const MAX_BODY_BYTES = 1_000_000;
const SYSTEM_PROMPT =
  'You are a hospital report assistant. Answer only from the report data given. ' +
  'Never invent numbers. If the data does not have the answer, say so. ' +
  'Money is in Taka (Tk). Keep answers short. You cannot change any data; ' +
  'refuse requests to edit or delete.';

// The PHP website is served by Apache at localhost. Allow only local browser
// origins needed for local development; do not use a wildcard origin.
const ALLOWED_ORIGINS = new Set([
  'http://localhost',
  'http://127.0.0.1',
]);

function sendJson(res, statusCode, payload, origin) {
  const body = JSON.stringify(payload);
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Cache-Control': 'no-store',
    ...(origin && ALLOWED_ORIGINS.has(origin)
      ? {
          'Access-Control-Allow-Origin': origin,
          'Vary': 'Origin',
          'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
          'Access-Control-Allow-Headers': 'Content-Type',
        }
      : {}),
  });
  res.end(body);
}

function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    let raw = '';
    let size = 0;

    req.setEncoding('utf8');
    req.on('data', (chunk) => {
      size += Buffer.byteLength(chunk, 'utf8');
      if (size > MAX_BODY_BYTES) {
        reject(new Error('Request body is too large.'));
        req.destroy();
        return;
      }
      raw += chunk;
    });
    req.on('end', () => {
      try {
        resolve(JSON.parse(raw || '{}'));
      } catch {
        reject(new Error('Request body must be valid JSON.'));
      }
    });
    req.on('error', reject);
  });
}

function getModelChunkContent(streamText) {
  const pieces = [];
  const blocks = streamText.split(/\r?\n\r?\n/);

  for (const block of blocks) {
    const lines = block.split(/\r?\n/);
    let eventName = '';
    const dataLines = [];

    for (const line of lines) {
      if (line.startsWith('event:')) {
        eventName = line.slice('event:'.length).trim();
      } else if (line.startsWith('data:')) {
        dataLines.push(line.slice('data:'.length).trim());
      }
    }

    if (eventName !== 'model_chunk' || dataLines.length === 0) continue;

    try {
      const eventData = JSON.parse(dataLines.join('\n'));
      if (typeof eventData.content === 'string') {
        pieces.push(eventData.content);
      }
    } catch {
      // Ignore non-JSON or malformed SSE blocks; only model_chunk content
      // events are used to construct the answer.
    }
  }

  return pieces.join('');
}

async function askChatterMate(question, data) {
  if (!API_KEY) {
    throw new Error('Server configuration is missing CHATTERMATE_API_KEY in .env.');
  }

  const commonHeaders = {
    'X-API-Key': API_KEY,
    'Accept': 'application/json',
  };

  const modelsResponse = await fetch('https://chattermate.ai/api/chat/models', {
    method: 'GET',
    headers: commonHeaders,
    signal: AbortSignal.timeout(30_000),
  });

  if (!modelsResponse.ok) {
    throw new Error(`ChatterMate models request failed (HTTP ${modelsResponse.status}).`);
  }

  const modelInfo = await modelsResponse.json();
  const modelId = modelInfo.default_model_id;
  if (modelId === undefined || modelId === null || modelId === '') {
    throw new Error('ChatterMate did not return a default_model_id.');
  }

  const prompt =
    'Report data: ' + JSON.stringify(data) + '\nQuestion: ' + question;

  const chatResponse = await fetch('https://chattermate.ai/api/chat/stream', {
    method: 'POST',
    headers: {
      ...commonHeaders,
      'Content-Type': 'application/json',
      'Accept': 'text/event-stream',
    },
    body: JSON.stringify({
      models: [modelId],
      system_prompt: SYSTEM_PROMPT,
      prompt,
    }),
    signal: AbortSignal.timeout(60_000),
  });

  if (!chatResponse.ok) {
    // Do not return upstream response bodies, which could contain sensitive
    // implementation details, directly to the browser.
    throw new Error(`ChatterMate chat request failed (HTTP ${chatResponse.status}).`);
  }

  const streamText = await chatResponse.text();
  const answer = getModelChunkContent(streamText).trim();
  if (!answer) {
    throw new Error('ChatterMate returned no answer content.');
  }

  return answer;
}

const server = http.createServer(async (req, res) => {
  const origin = req.headers.origin;
  const requestUrl = new URL(req.url, `http://${req.headers.host || 'localhost'}`);

  if (req.method === 'OPTIONS') {
    if (origin && !ALLOWED_ORIGINS.has(origin)) {
      res.writeHead(403);
      res.end();
      return;
    }
    res.writeHead(204, {
      ...(origin
        ? {
            'Access-Control-Allow-Origin': origin,
            'Vary': 'Origin',
            'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
            'Access-Control-Allow-Headers': 'Content-Type',
          }
        : {}),
      'Access-Control-Max-Age': '600',
    });
    res.end();
    return;
  }

  if (origin && !ALLOWED_ORIGINS.has(origin)) {
    sendJson(res, 403, { error: 'Origin not allowed.' });
    return;
  }

  if (req.method === 'GET' && requestUrl.pathname === '/health') {
    sendJson(res, 200, { ok: true, apiKeyConfigured: Boolean(API_KEY) }, origin);
    return;
  }

  if (req.method !== 'POST' || requestUrl.pathname !== '/ask') {
    sendJson(res, 404, { error: 'Not found.' }, origin);
    return;
  }

  try {
    const body = await readJsonBody(req);
    const question = typeof body.question === 'string' ? body.question.trim() : '';

    if (!question) {
      sendJson(res, 400, { error: 'A question is required.' }, origin);
      return;
    }
    if (question.length > 4_000) {
      sendJson(res, 400, { error: 'Question is too long.' }, origin);
      return;
    }
    if (body.data === undefined || body.data === null || typeof body.data !== 'object') {
      sendJson(res, 400, { error: 'Report data must be provided as an object or array.' }, origin);
      return;
    }

    const answer = await askChatterMate(question, body.data);
    sendJson(res, 200, { answer }, origin);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected server error.';
    const status = message.includes('valid JSON') || message.includes('too large') ? 400 : 502;
    console.error(`[${new Date().toISOString()}] /ask error: ${message}`);
    sendJson(res, status, { error: message }, origin);
  }
});

server.listen(PORT, HOST, () => {
  console.log(`Hania's Health Hub AI API is listening at http://localhost:${PORT}`);
  if (!API_KEY) {
    console.warn('CHATTERMATE_API_KEY is not set. Add it to the project-root .env file.');
  }
});

server.on('error', (error) => {
  console.error('Could not start the AI API server:', error.message);
  process.exitCode = 1;
});