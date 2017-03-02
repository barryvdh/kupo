import axios from 'axios'
import event from './event'

export default {
  checking: false,

  check (url) {
    if (this.checking) {
      return
    }

    this.checking = true
    event.emit('check-start')

    if (!url) {
      event.emit('check-done', [])
      this.checking = false
      return
    }

    axios.get(`check?url=${url}`)
      .then(response => {
        event.emit('check-done', response.data)
        this.checking = false
      })
      .catch(() => {
        event.emit('check-done', false)
        this.checking = false
      })
  }
}
