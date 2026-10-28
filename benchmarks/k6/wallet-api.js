import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const profile = __ENV.PROFILE || 'smoke';
const profiles = {
  smoke: {
    executor: 'constant-arrival-rate',
    rate: Number(__ENV.RATE || 5),
    timeUnit: '1s',
    duration: __ENV.DURATION || '30s',
    preAllocatedVUs: 10,
    maxVUs: 50,
  },
  sustained: {
    executor: 'constant-arrival-rate',
    rate: Number(__ENV.RATE || 1500),
    timeUnit: '1s',
    duration: __ENV.DURATION || '30m',
    preAllocatedVUs: 500,
    maxVUs: 4000,
  },
  burst: {
    executor: 'constant-arrival-rate',
    rate: Number(__ENV.RATE || 5000),
    timeUnit: '1s',
    duration: __ENV.DURATION || '15m',
    preAllocatedVUs: 1500,
    maxVUs: 10000,
  },
};

if (!profiles[profile]) {
  throw new Error(`Unknown PROFILE=${profile}; use smoke, sustained, or burst.`);
}

export const options = {
  scenarios: { wallet_api: profiles[profile] },
  thresholds: {
    unexpected_errors: ['rate<0.01'],
    read_duration: ['p(95)<=200', 'p(99)<=500'],
    write_duration: ['p(95)<=400', 'p(99)<=1000'],
    dropped_iterations: ['count==0'],
  },
};

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const gatewaySecret = __ENV.GATEWAY_SECRET || 'development-gateway-secret';
const wallets = (__ENV.WALLET_IDS || '018f0000-0000-7000-8000-000000000001,018f0000-0000-7000-8000-000000000002')
  .split(',')
  .map((value) => value.trim())
  .filter(Boolean);
const hotWallet = __ENV.MODE === 'hot_wallet';

if (wallets.length < 2) {
  throw new Error('WALLET_IDS must contain at least two comma-separated user wallet UUIDs.');
}

const unexpectedErrors = new Rate('unexpected_errors');
const readDuration = new Trend('read_duration', true);
const writeDuration = new Trend('write_duration', true);
const pendingDeposits = [];
const knownDeposits = [];

function requestId(prefix) {
  return `${prefix}-${__VU}-${__ITER}-${Date.now()}-${Math.floor(Math.random() * 1e9)}`;
}

function sourceWallet() {
  return hotWallet ? wallets[0] : wallets[Math.floor(Math.random() * wallets.length)];
}

function destinationWallet(source) {
  const candidates = wallets.filter((wallet) => wallet !== source);
  return candidates[Math.floor(Math.random() * candidates.length)];
}

function record(response, expectedStatuses, kind, acceptedBusinessCodes = []) {
  let businessCode = null;
  try {
    businessCode = response.json('code');
  } catch (_) {
    // Non-JSON failures remain unexpected.
  }

  const expected = expectedStatuses.includes(response.status)
    || (response.status === 422 && acceptedBusinessCodes.includes(businessCode));
  unexpectedErrors.add(!expected);
  (kind === 'read' ? readDuration : writeDuration).add(response.timings.duration);
  check(response, { [`${kind} returned an expected status`]: () => expected });
}

function historyRead() {
  const response = http.get(`${baseUrl}/api/v1/wallets/${sourceWallet()}/transactions?per_page=25`, {
    tags: { operation: 'history', kind: 'read' },
  });
  record(response, [200], 'read');
}

function statusRead() {
  if (knownDeposits.length === 0) {
    historyRead();
    return;
  }

  const deposit = knownDeposits[Math.floor(Math.random() * knownDeposits.length)];
  const response = http.get(`${baseUrl}/api/v1/deposits/${deposit.deposit_id}`, {
    tags: { operation: 'deposit_status', kind: 'read' },
  });
  record(response, [200], 'read');
}

function transfer() {
  const source = sourceWallet();
  const response = http.post(
    `${baseUrl}/api/v1/wallets/${source}/transfers`,
    JSON.stringify({ destination_wallet_id: destinationWallet(source), amount_minor: 1 }),
    {
      headers: { 'Content-Type': 'application/json', 'Idempotency-Key': requestId('transfer') },
      tags: { operation: 'transfer', kind: 'write' },
    },
  );
  record(response, [200], 'write', ['INSUFFICIENT_FUNDS']);
}

function createDeposit() {
  const response = http.post(
    `${baseUrl}/api/v1/wallets/${sourceWallet()}/deposits`,
    JSON.stringify({ amount_minor: 1 }),
    {
      headers: { 'Content-Type': 'application/json', 'Idempotency-Key': requestId('deposit') },
      tags: { operation: 'deposit_create', kind: 'write' },
    },
  );
  record(response, [201], 'write');

  if (response.status === 201) {
    const deposit = response.json('data');
    pendingDeposits.push(deposit);
    knownDeposits.push(deposit);
  }
}

function confirmDeposit() {
  if (pendingDeposits.length === 0) {
    createDeposit();
    return;
  }

  const deposit = pendingDeposits.shift();
  const response = http.post(
    `${baseUrl}/api/v1/gateway/deposits/${deposit.deposit_id}/confirm`,
    JSON.stringify({
      gateway_reference: deposit.gateway_reference,
      amount_minor: deposit.amount_minor,
    }),
    {
      headers: { 'Content-Type': 'application/json', 'X-Gateway-Secret': gatewaySecret },
      tags: { operation: 'deposit_confirm', kind: 'write' },
    },
  );
  record(response, [200], 'write');
}

export default function () {
  const choice = Math.random();

  if (choice < 0.55) historyRead();
  else if (choice < 0.70) statusRead();
  else if (choice < 0.90) transfer();
  else if (choice < 0.95) createDeposit();
  else confirmDeposit();
}
