/**
 * Hubs Start — curated icon resolver.
 *
 * Descriptors and the persona action catalog reference vue-material-design-icons
 * by name (e.g. 'FileSign'). We can't dynamically import arbitrary names without
 * bundling the whole icon set, so this is a curated static map covering the
 * persona primary-action icons + common badge/row icons. Unknown names fall back
 * to a neutral dot — never a build/runtime error.
 */

import CircleMedium from 'vue-material-design-icons/CircleMedium.vue'

// Persona primary-action icons (from personaConfig.js)
import InboxArrowDown from 'vue-material-design-icons/InboxArrowDown.vue'
import FolderLockOpen from 'vue-material-design-icons/FolderLockOpen.vue'
import EmailFast from 'vue-material-design-icons/EmailFast.vue'
import VideoPlus from 'vue-material-design-icons/VideoPlus.vue'
import FileSign from 'vue-material-design-icons/FileSign.vue'
import BellPlus from 'vue-material-design-icons/BellPlus.vue'
import FileDocumentEdit from 'vue-material-design-icons/FileDocumentEdit.vue'
import FileSearch from 'vue-material-design-icons/FileSearch.vue'
import CalendarAccount from 'vue-material-design-icons/CalendarAccount.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import ArchiveArrowDown from 'vue-material-design-icons/ArchiveArrowDown.vue'
import CheckDecagram from 'vue-material-design-icons/CheckDecagram.vue'
import AlertDecagram from 'vue-material-design-icons/AlertDecagram.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import FolderHeart from 'vue-material-design-icons/FolderHeart.vue'
import VideoAccount from 'vue-material-design-icons/VideoAccount.vue'
import DrawPen from 'vue-material-design-icons/DrawPen.vue'
import FileChart from 'vue-material-design-icons/FileChart.vue'
import EmailAlert from 'vue-material-design-icons/EmailAlert.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import ShieldAlert from 'vue-material-design-icons/ShieldAlert.vue'
import FileExport from 'vue-material-design-icons/FileExport.vue'
import DatabaseSearch from 'vue-material-design-icons/DatabaseSearch.vue'
import AccountKey from 'vue-material-design-icons/AccountKey.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'

// Common badge / row / status icons
import ClockAlert from 'vue-material-design-icons/ClockAlert.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import ShieldLock from 'vue-material-design-icons/ShieldLock.vue'
import MessageTextLock from 'vue-material-design-icons/MessageTextLock.vue'
import Fax from 'vue-material-design-icons/Fax.vue'
import CellphoneMessage from 'vue-material-design-icons/CellphoneMessage.vue'
import Bank from 'vue-material-design-icons/Bank.vue'
import Forum from 'vue-material-design-icons/Forum.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import FolderLock from 'vue-material-design-icons/FolderLock.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import ProgressClock from 'vue-material-design-icons/ProgressClock.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import AccountAlert from 'vue-material-design-icons/AccountAlert.vue'
import Counter from 'vue-material-design-icons/Counter.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import FileLock from 'vue-material-design-icons/FileLock.vue'
import FileSync from 'vue-material-design-icons/FileSync.vue'
import FileCheck from 'vue-material-design-icons/FileCheck.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import BellRing from 'vue-material-design-icons/BellRing.vue'
import Email from 'vue-material-design-icons/Email.vue'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import TimerSand from 'vue-material-design-icons/TimerSand.vue'
import LockCheck from 'vue-material-design-icons/LockCheck.vue'
import Send from 'vue-material-design-icons/Send.vue'
import EyeCheck from 'vue-material-design-icons/EyeCheck.vue'
import HospitalBox from 'vue-material-design-icons/HospitalBox.vue'
import FilePdfBox from 'vue-material-design-icons/FilePdfBox.vue'
import AccountSupervisor from 'vue-material-design-icons/AccountSupervisor.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import PlaylistPlus from 'vue-material-design-icons/PlaylistPlus.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'

const MAP = {
	InboxArrowDown, FolderLockOpen, EmailFast, VideoPlus, FileSign, BellPlus,
	FileDocumentEdit, FileSearch, CalendarAccount, Gavel, ArchiveArrowDown,
	CheckDecagram, AlertDecagram, AccountGroup, FolderHeart, VideoAccount, DrawPen,
	FileChart, EmailAlert, CalendarClock, ShieldAlert, FileExport, DatabaseSearch,
	AccountKey, ChartBoxOutline,
	ClockAlert, ClockOutline, AlertCircleOutline, AlertOutline, ShieldCheck,
	ShieldLock, MessageTextLock, Fax, CellphoneMessage, Bank, Forum,
	AccountShare: ShareVariant, ShareVariant,
	FolderLock, Archive, ProgressClock, ClipboardCheckOutline, AccountAlert, Counter,
	CashMultiple, FileLock, FileSync, FileCheck, CheckCircle, CheckCircleOutline,
	BellRing, Email, CalendarCheck, Pencil, ChartLine, ScaleBalance, TimerSand,
	LockCheck, Send, EyeCheck, HospitalBox, FilePdfBox, AccountSupervisor,
	FileDocumentOutline, PlaylistPlus, TextBoxOutline,
}

/**
 * Resolve an icon name to a component. Unknown / missing names → a neutral dot.
 * @param {?string} name vue-material-design-icons PascalCase name
 * @return {object} a Vue component (never null)
 */
export function iconFor(name) {
	return (name && MAP[name]) || CircleMedium
}

export default { iconFor }
