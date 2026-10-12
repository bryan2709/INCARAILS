import assert from 'node:assert/strict';
import { test } from 'node:test';
import { api } from '../src/lib/api.js';

test('API errors keep their HTTP status, public code and message for unavailable-resource navigation', async () => {
  const adapter = api.defaults.adapter;
  api.defaults.adapter = async () => {
    throw Object.assign(new Error('Request failed with status code 404'), {
      response: { status: 404, data: { message: 'No hay información local de esta competición.', error: { code: 'NOT_FOUND', details: null } } },
    });
  };
  try {
    await assert.rejects(api.get('/competitions/2145'), failure => {
      assert.equal(failure.status, 404);
      assert.equal(failure.code, 'NOT_FOUND');
      assert.equal(failure.message, 'No hay información local de esta competición.');
      return true;
    });
  } finally {
    api.defaults.adapter = adapter;
  }
});
