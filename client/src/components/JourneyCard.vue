<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePublicLineStore } from '@/stores/publicLine'
import { formatMinute2HM, isLatinLocale, nameOf, buildRouteText } from '@/utils/format'

const props = defineProps({
  journey: { type: Object, required: true },
  index: { type: Number, default: 0 },
})

const { t } = useI18n()
const lineStore = usePublicLineStore()

/** 標記（Fastest、ShortestTime…）改用本地語言 */
const tagLabels = computed(() => (props.journey.Tags || []).map((tag) => t(`journey.tag.${tag}`)))

const legs = computed(() => props.journey.Legs || [])

const units = computed(() => ({
  minute: t('time.minuteShort'),
  hour: t('time.hourShort'),
}))

function formatDuration(minutes) {
  return formatMinute2HM(minutes, units.value)
}

function legColor(leg) {
  if (leg.Type !== 'ride') return '#999999'
  return lineStore.lineColor(leg.LineUID)
}

function legLineName(leg) {
  if (leg.Type !== 'ride') return ''
  if (leg.LineName) return nameOf(leg.LineName)
  return lineStore.lineName(leg.LineUID)
}

function trainLabel(leg) {
  if (leg.Type !== 'ride') return ''
  if (leg.TrainNo) return leg.TrainNo
  if (leg.Train) return leg.Train
  return ''
}

function classLabel(leg) {
  if (leg.Type !== 'ride') return ''
  const typeName = leg.TrainType ? nameOf(leg.TrainType.Name) || leg.TrainType.Code : ''
  if (isLatinLocale()) return typeName || leg.ClassLabel || ''
  return leg.ClassLabel || typeName
}

function headSign(leg) {
  if (leg.Type !== 'ride') return ''
  if (isLatinLocale() && leg.Destination) {
    return `${t('journey.direction')} ${nameOf(leg.Destination.StationName)}`
  }
  if (leg.HeadSign) return leg.HeadSign
  if (leg.Destination) return `${t('journey.direction')} ${nameOf(leg.Destination.StationName)}`
  return ''
}

const routeText = computed(() =>
  buildRouteText(props.journey, {
    t,
    lineName: (uid) => lineStore.lineName(uid),
  }),
)
</script>

<template>
  <div class="journey-card">
    <div class="header">
      <div class="sub num">
        {{ t('journey.no') }} <span class="val">{{ index + 1 }}</span>
      </div>
      <div class="sub count">
        {{ t('journey.transferCount') }}: <span class="val">{{ journey.TransferCount }}</span>
      </div>
      <div class="sub totalTime">
        {{ t('journey.travelTime') }}: <span class="val">{{ formatDuration(journey.TravelMinutes) }}</span>
      </div>
      <div class="sub startTime">
        {{ t('journey.startTime') }}: <span class="val">{{ journey.DepartureTime }}</span>
      </div>
      <div class="sub endTime">
        {{ t('journey.endTime') }}: <span class="val">{{ journey.ArrivalTime }}</span>
      </div>
      <div v-if="tagLabels.length" class="tags">
        <span v-for="label in tagLabels" :key="label" class="tag">{{ label }}</span>
      </div>
    </div>

    <div class="route-text">{{ routeText }}</div>

    <div class="table-scroll">
      <table class="journey-table">
        <thead>
          <tr>
            <th class="sect">{{ t('journey.col.leg') }}</th>
            <th class="time">{{ t('journey.col.time') }}</th>
            <th class="station">{{ t('journey.col.station') }}</th>
            <th class="info">{{ t('journey.col.info') }}</th>
            <th class="line">{{ t('journey.col.line') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(leg, i) in legs" :key="i">
            <td class="sect">{{ i + 1 }}</td>

            <td class="time">
              <div v-if="leg.Type === 'ride'" class="time-box">
                <div class="value start">{{ leg.DepartureTime }}</div>
                <div class="deep"><div class="minute">{{ formatDuration(leg.TravelMinutes) }}</div></div>
                <div class="value end">{{ leg.ArrivalTime }}</div>
              </div>
              <div v-else class="time-box walk">
                <div class="minute">{{ t('journey.walkShort', { min: leg.Minutes }) }}</div>
              </div>
            </td>

            <td class="station">
              <div class="station-box">
                <div class="value from">{{ nameOf(leg.From?.StationName) }}</div>
                <div class="deep">
                  <div class="line-color" :style="{ background: legColor(leg) }"><div class="bd"></div></div>
                </div>
                <div class="value to">{{ nameOf(leg.To?.StationName) }}</div>
              </div>
            </td>

            <td class="info">
              <div v-if="leg.Type === 'ride'" class="info-box">
                <div v-if="trainLabel(leg)">{{ t('journey.trainNo') }}: {{ trainLabel(leg) }}</div>
                <div v-if="classLabel(leg)">{{ t('journey.trainClass') }}: {{ classLabel(leg) }}</div>
                <div v-if="headSign(leg)">{{ headSign(leg) }}</div>
                <div v-if="typeof leg.Stops === 'number'">{{ t('journey.stops') }}: {{ leg.Stops }}</div>
                <div v-if="leg.DataTier && leg.DataTier !== 'real'" class="derived">
                  {{ t('journey.derived') }}
                </div>
              </div>
              <div v-else class="info-box">
                <div>{{ t('journey.walk', { min: leg.Minutes }) }}</div>
                <a
                  v-for="v in leg.Videos || []"
                  :key="v.ID"
                  class="video"
                  :href="v.Url"
                  :title="v.Title || ''"
                  target="_blank"
                  rel="noopener"
                  >{{ t('journey.video') }}</a
                >
              </div>
            </td>

            <td class="line">
              <span
                v-if="leg.Type === 'ride'"
                class="line-badge"
                :style="{ background: legColor(leg), color: '#fff' }"
                >{{ legLineName(leg) }}</span
              >
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
