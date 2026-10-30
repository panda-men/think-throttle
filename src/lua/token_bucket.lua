-- Up to 16 buckets. Rejection performs no writes and consumes no other quota.
local clock = redis.call('TIME')
local now = tonumber(clock[1]) * 1000 + math.floor(tonumber(clock[2]) / 1000)
local buckets = {}
local retry = 0
local selected = 1
local lowest = 2

for i = 1, #KEYS do
    local offset = (i - 1) * 5
    local capacity = tonumber(ARGV[offset + 1])
    local refill = tonumber(ARGV[offset + 2])
    local interval = tonumber(ARGV[offset + 3])
    local cost = tonumber(ARGV[offset + 4])
    local ttl = tonumber(ARGV[offset + 5])
    local state = redis.call('HMGET', KEYS[i], 'tokens', 'updated')
    local tokens = capacity
    if state[1] or state[2] then
        local stored = tonumber(state[1])
        local updated = tonumber(state[2])
        if not stored or not updated or updated < 0 or stored < 0 or stored > capacity then
            return redis.error_reply('Invalid rate limit state')
        end
        tokens = math.min(capacity, stored + math.max(0, now - updated) * refill / interval)
    end
    local wait = math.max(0, math.ceil((cost - tokens) * interval / refill))
    local ratio = (tokens - cost) / capacity
    if wait > retry then
        retry = wait
        selected = i
    elseif retry == 0 and ratio < lowest then
        lowest = ratio
        selected = i
    end
    buckets[i] = {tokens = tokens, capacity = capacity, refill = refill, interval = interval, cost = cost, ttl = ttl}
end

if retry > 0 then
    local bucket = buckets[selected]
    return {0, retry, selected, bucket.capacity, math.floor(bucket.tokens),
        math.ceil((bucket.capacity - bucket.tokens) * bucket.interval / bucket.refill), now}
end

for i = 1, #KEYS do
    local bucket = buckets[i]
    bucket.tokens = math.max(0, bucket.tokens - bucket.cost)
    redis.call('HSET', KEYS[i], 'tokens', bucket.tokens, 'updated', now)
    redis.call('PEXPIRE', KEYS[i], bucket.ttl)
end
local bucket = buckets[selected]
return {1, 0, selected, bucket.capacity, math.floor(bucket.tokens),
    math.ceil((bucket.capacity - bucket.tokens) * bucket.interval / bucket.refill), now}
