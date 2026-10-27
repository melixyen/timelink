<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePublicLineStore } from '@/stores/publicLine'
import { formatMinute2HM, isLatinLocale, nameOf, buildRouteText } from '@/utils/format'
import TimeBox from '@/components/TimeBox.vue'
import StationBox from '@/components/StationBox.vue'

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

/** 鐵路系統（台鐵、高鐵）：線條中央加白色缺口，模仿地圖圖例的黑白相間軌道 */
function isRail(leg) {
  if (leg.Type !== 'ride') return false
  const sys = leg.System || String(leg.LineUID || '').split('-')[0]
  return sys === 'TRA' || sys === 'THSR'
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

/**
 * 車種名稱（例如「自強」）。
 * 後端的 TrainType.Name.Zh_tw 會帶很長的括號補充說明
 * （例如「自強(推拉式自強號且無自行車車廂)」），顯示時只取前半，完整內容放在 title。
 */
function trainTypeText(leg) {
  if (leg.Type !== 'ride') return ''
  const name = leg.TrainType ? nameOf(leg.TrainType.Name) : ''
  if (!name) return ''
  return name.replace(/[（(].*$/, '').trim() || name
}

/** 車種的完整名稱（含括號說明），給 title 提示用 */
function trainTypeFull(leg) {
  if (leg.Type !== 'ride') return ''
  return leg.TrainType ? nameOf(leg.TrainType.Name) : ''
}

/**
 * 列車等級（區間車／對號列車／各站停／直達車）：後端依車種代碼或停靠站分類後給的固定名稱。
 * 英文、日文語系沒有中文等級名，所以那兩種語系只顯示車種名稱。
 */
function classLabel(leg) {
  if (leg.Type !== 'ride') return ''
  if (isLatinLocale()) return ''
  return leg.ClassLabel || ''
}

/** 車種欄位要顯示的文字：車種名稱為主，等級名稱不同時以括號補充 */
function trainClassText(leg) {
  const type = trainTypeText(leg)
  const cls = classLabel(leg)
  if (!type) return cls
  if (!cls || cls === type) return type
  if (cls.includes(type)) return cls
  return `${type}（${cls}）`
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
              <TimeBox
                v-if="leg.Type === 'ride'"
                type="ride"
                :departure-time="leg.DepartureTime"
                :arrival-time="leg.ArrivalTime"
                :duration="formatDuration(leg.TravelMinutes)"
              />
              <TimeBox
                v-else
                type="walk"
                :walk-text="t('journey.walkShort', { min: leg.Minutes })"
              />
            </td>

            <td class="station">
              <StationBox
                :from-name="nameOf(leg.From?.StationName)"
                :to-name="nameOf(leg.To?.StationName)"
                :color="legColor(leg)"
                :rail="isRail(leg)"
              />
            </td>

            <td class="info">
              <div v-if="leg.Type === 'ride'" class="info-box">
                <div v-if="trainLabel(leg)">{{ t('journey.trainNo') }}: {{ trainLabel(leg) }}</div>
                <div v-if="trainClassText(leg)" :title="trainTypeFull(leg)">
                  {{ t('journey.trainClass') }}: {{ trainClassText(leg) }}
                </div>
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
