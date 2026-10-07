import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  // Option A: Instant 100 VUs for 3 minutes
  vus: 100,
  duration: '3m',

  // Alternative (Option B): Staging / Ramp-up
  // Recommended for stress testing to verify system recovery:
  // stages: [
  //   { duration: '30s', target: 100 }, // Ramp up to 100 VUs
  //   { duration: '3m',  target: 100 }, // Hold 100 VUs (Stress Load)
  //   { duration: '30s', target: 0 },   // Ramp down to verify recovery
  // ],
};

export default function () {
  const params = {
    headers: {
      'Cookie': 'laravel_session=YOUR_ACTIVE_SESSION_COOKIE',
    },
  };

  const res = http.get('http://host.docker.internal/dcs/masterlist', params);

  check(res, {
    'status is 200': (r) => r.status === 200,
    'response time < 5s': (r) => r.timings.duration < 5000,
  });

  sleep(1); // 1 second user think time
}